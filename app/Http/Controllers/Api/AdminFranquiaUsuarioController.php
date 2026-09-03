<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Franquia;
use App\Models\FranquiaUsuario;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminFranquiaUsuarioController extends Controller
{
    /**
     * Lista todos os usuários (titular e assistentes) vinculados à franquia.
     */
    public function index(int $franquiaId)
    {
        $franquia = Franquia::findOrFail($franquiaId);

        $vinculos = FranquiaUsuario::where('franquia_id', $franquiaId)
            ->with('user:id,name,email,phone,active,created_at')
            ->orderByRaw("CASE WHEN tipo = 'titular' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        // Se ainda não existirem registros na tabela franquia_usuarios (fallback)
        if ($vinculos->isEmpty()) {
            $userContexts = UserContext::where('role', 'franquia')
                ->where('context_id', $franquiaId)
                ->with('user:id,name,email,phone,active,created_at')
                ->get();

            $usuarios = $userContexts->map(function ($ctx) use ($franquia) {
                $isTitular = ($ctx->user_id == $franquia->titular_user_id) || true;
                return [
                    'id'         => $ctx->id,
                    'user_id'    => $ctx->user_id,
                    'nome'       => $ctx->user?->name,
                    'email'      => $ctx->user?->email,
                    'telefone'   => $ctx->user?->phone,
                    'tipo'       => $isTitular ? 'titular' : 'assistente',
                    'cargo'      => $isTitular ? 'Franqueado Titular' : 'Assistente Administrativo',
                    'ativo'      => (bool) $ctx->user?->active,
                    'created_at' => $ctx->user?->created_at,
                ];
            });

            return response()->json([
                'franquia_id'          => $franquia->id,
                'franquia_nome'        => $franquia->nome,
                'modulo_multiusuario'  => (bool) $franquia->modulo_multiusuario,
                'usuarios'             => $usuarios,
            ]);
        }

        $usuarios = $vinculos->map(function ($v) {
            return [
                'id'         => $v->id,
                'user_id'    => $v->user_id,
                'nome'       => $v->user?->name,
                'email'      => $v->user?->email,
                'telefone'   => $v->user?->phone,
                'tipo'       => $v->tipo,
                'cargo'      => $v->cargo ?? ($v->tipo === 'titular' ? 'Franqueado Titular' : 'Assistente Administrativo'),
                'ativo'      => (bool) ($v->ativo && ($v->user?->active ?? true)),
                'created_at' => $v->created_at ?? $v->user?->created_at,
            ];
        });

        return response()->json([
            'franquia_id'          => $franquia->id,
            'franquia_nome'        => $franquia->nome,
            'modulo_multiusuario'  => (bool) $franquia->modulo_multiusuario,
            'usuarios'             => $usuarios,
        ]);
    }

    /**
     * Cadastra um novo assistente administrativo para a franquia.
     */
    public function store(Request $request, int $franquiaId)
    {
        $franquia = Franquia::findOrFail($franquiaId);

        if (!$franquia->modulo_multiusuario) {
            return response()->json([
                'message' => 'O módulo multiusuários não está liberado para esta franquia. Ative o módulo nas permissões da franquia primeiro.',
            ], 403);
        }

        $data = $request->validate([
            'nome'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'senha'    => 'required|string|min:6',
            'telefone' => 'nullable|string|max:20',
            'cargo'    => 'nullable|string|max:100',
        ], [
            'email.unique' => 'Já existe um usuário cadastrado com este e-mail.',
        ]);

        $cargo = !empty($data['cargo']) ? $data['cargo'] : 'Assistente Administrativo';

        $vinculo = DB::transaction(function () use ($data, $cargo, $franquia, $request) {
            $user = User::create([
                'name'     => $data['nome'],
                'email'    => $data['email'],
                'phone'    => $data['telefone'] ?? null,
                'password' => Hash::make($data['senha']),
                'active'   => true,
            ]);

            UserRole::firstOrCreate([
                'user_id' => $user->id,
                'role'    => 'franquia',
            ]);

            UserContext::create([
                'user_id'    => $user->id,
                'role'       => 'franquia',
                'context_id' => $franquia->id,
            ]);

            return FranquiaUsuario::create([
                'franquia_id' => $franquia->id,
                'user_id'     => $user->id,
                'tipo'        => 'assistente',
                'cargo'       => $cargo,
                'ativo'       => true,
                'created_by'  => $request->user()?->id,
            ]);
        });

        AuditService::log(
            action: 'franquia.usuario_criado',
            descricao: "Admin {$request->user()?->name} cadastrou o assistente {$data['nome']} ({$data['email']}) na franquia {$franquia->nome}",
            franquiaId: $franquia->id,
            entity: $vinculo,
            dadosNovos: [
                'user_id' => $vinculo->user_id,
                'nome'    => $data['nome'],
                'email'   => $data['email'],
                'cargo'   => $cargo,
            ],
            request: $request
        );

        return response()->json([
            'id'         => $vinculo->id,
            'user_id'    => $vinculo->user_id,
            'nome'       => $data['nome'],
            'email'      => $data['email'],
            'telefone'   => $data['telefone'] ?? null,
            'tipo'       => 'assistente',
            'cargo'      => $cargo,
            'ativo'      => true,
            'created_at' => $vinculo->created_at,
        ], 201);
    }

    /**
     * Atualiza os dados de um assistente administrativo.
     */
    public function update(Request $request, int $franquiaId, int $id)
    {
        $franquia = Franquia::findOrFail($franquiaId);
        $vinculo  = FranquiaUsuario::where('franquia_id', $franquiaId)->with('user')->findOrFail($id);

        $data = $request->validate([
            'nome'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email,' . $vinculo->user_id,
            'senha'    => 'nullable|string|min:6',
            'telefone' => 'nullable|string|max:20',
            'cargo'    => 'nullable|string|max:100',
        ], [
            'email.unique' => 'Já existe outro usuário cadastrado com este e-mail.',
        ]);

        $dadosAntigos = [
            'nome'  => $vinculo->user?->name,
            'email' => $vinculo->user?->email,
            'cargo' => $vinculo->cargo,
        ];

        DB::transaction(function () use ($data, $vinculo) {
            $userUpdate = [
                'name'  => $data['nome'],
                'email' => $data['email'],
                'phone' => $data['telefone'] ?? $vinculo->user?->phone,
            ];
            if (!empty($data['senha'])) {
                $userUpdate['password'] = Hash::make($data['senha']);
            }
            $vinculo->user?->update($userUpdate);

            if (isset($data['cargo'])) {
                $vinculo->update(['cargo' => $data['cargo']]);
            }
        });

        AuditService::log(
            action: 'franquia.usuario_atualizado',
            descricao: "Admin {$request->user()?->name} atualizou o usuário {$data['nome']} da franquia {$franquia->nome}",
            franquiaId: $franquia->id,
            entity: $vinculo,
            dadosAnteriores: $dadosAntigos,
            dadosNovos: [
                'nome'  => $data['nome'],
                'email' => $data['email'],
                'cargo' => $vinculo->cargo,
            ],
            request: $request
        );

        $vinculo->refresh();

        return response()->json([
            'id'         => $vinculo->id,
            'user_id'    => $vinculo->user_id,
            'nome'       => $vinculo->user?->name,
            'email'      => $vinculo->user?->email,
            'telefone'   => $vinculo->user?->phone,
            'tipo'       => $vinculo->tipo,
            'cargo'      => $vinculo->cargo,
            'ativo'      => (bool) $vinculo->ativo,
            'created_at' => $vinculo->created_at,
        ]);
    }

    /**
     * Alterna o status ativo/inativo do assistente.
     */
    public function toggleActive(Request $request, int $franquiaId, int $id)
    {
        $franquia = Franquia::findOrFail($franquiaId);
        $vinculo  = FranquiaUsuario::where('franquia_id', $franquiaId)->with('user')->findOrFail($id);

        $novo = !$vinculo->ativo;

        DB::transaction(function () use ($vinculo, $novo) {
            $vinculo->update(['ativo' => $novo]);
            $vinculo->user?->update(['active' => $novo]);
        });

        AuditService::log(
            action: 'franquia.usuario_status_alterado',
            descricao: "Admin {$request->user()?->name} alterou status do usuário {$vinculo->user?->name} para " . ($novo ? 'Ativo' : 'Inativo'),
            franquiaId: $franquia->id,
            entity: $vinculo,
            dadosNovos: ['ativo' => $novo],
            request: $request
        );

        return response()->json(['ativo' => $novo]);
    }

    /**
     * Remove o vínculo e o usuário assistente.
     */
    public function destroy(Request $request, int $franquiaId, int $id)
    {
        $franquia = Franquia::findOrFail($franquiaId);
        $vinculo  = FranquiaUsuario::where('franquia_id', $franquiaId)->with('user')->findOrFail($id);

        if ($vinculo->tipo === 'titular') {
            return response()->json([
                'message' => 'O franqueado titular não pode ser removido por aqui. Para remover o titular, edite os dados principais da franquia ou remova a franquia.',
            ], 422);
        }

        $userId   = $vinculo->user_id;
        $userName = $vinculo->user?->name;
        $userEmail= $vinculo->user?->email;

        DB::transaction(function () use ($vinculo, $userId, $franquiaId) {
            $vinculo->delete();
            UserContext::where('user_id', $userId)
                ->where('role', 'franquia')
                ->where('context_id', $franquiaId)
                ->delete();

            // Se o usuário não estiver em nenhum outro contexto, remove o usuário
            $hasOtherContexts = UserContext::where('user_id', $userId)->exists();
            if (!$hasOtherContexts) {
                UserRole::where('user_id', $userId)->delete();
                User::where('id', $userId)->delete();
            }
        });

        AuditService::log(
            action: 'franquia.usuario_removido',
            descricao: "Admin {$request->user()?->name} removeu o assistente {$userName} ({$userEmail}) da franquia {$franquia->nome}",
            franquiaId: $franquia->id,
            dadosAnteriores: [
                'user_id' => $userId,
                'nome'    => $userName,
                'email'   => $userEmail,
            ],
            request: $request
        );

        return response()->noContent();
    }
}
