<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaArquivo;
use App\Models\EmpresaFollowup;
use App\Models\Franquia;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class FranquiaEmpresaGestaoController extends Controller
{
    use HasTokenContext;

    private function assertPremium(int $franquiaId): void
    {
        $franquia = Franquia::findOrFail($franquiaId);
        if ($franquia->tipo !== 'premium') {
            abort(403, 'Este módulo requer franquia do tipo Premium.');
        }
    }

    // GET /franquia/empresas
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $query = Empresa::where('franquia_id', $franquiaId)
            ->withCount('vagas as total_vagas');

        if ($request->filled('status')) {
            $query->where('active', $request->status === 'ativa');
        }

        $empresas = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $empresas->getCollection()->map(fn($e) => [
                'id'           => $e->id,
                'razao_social' => $e->razao_social,
                'nome_fantasia'=> $e->nome_fantasia,
                'cnpj'         => $e->cnpj,
                'cidade'       => $e->cidade,
                'estado'       => $e->estado,
                'email'        => $e->email,
                'telefone'     => $e->telefone,
                'active'       => $e->active,
                'total_vagas'  => $e->total_vagas,
                'created_at'   => $e->created_at,
            ]),
            'meta' => [
                'total'        => $empresas->total(),
                'per_page'     => $empresas->perPage(),
                'current_page' => $empresas->currentPage(),
                'last_page'    => $empresas->lastPage(),
            ],
        ]);
    }

    // POST /franquia/empresas
    public function store(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $this->assertPremium($franquiaId);

        $validated = $request->validate([
            'razao_social'          => 'required|string|max:255',
            'nome_fantasia'         => 'nullable|string|max:255',
            'cnpj'                  => 'required|string|max:18|unique:empresas,cnpj',
            'email'                 => 'required|email|max:255',
            'telefone'              => 'nullable|string|max:20',
            'cep'                   => 'nullable|string|max:9',
            'logradouro'            => 'nullable|string|max:255',
            'numero'                => 'nullable|string|max:20',
            'complemento'           => 'nullable|string|max:100',
            'bairro'                => 'nullable|string|max:100',
            'cidade'                => 'nullable|string|max:100',
            'estado'                => 'nullable|string|size:2',
            'descricao'             => 'nullable|string',
            'prazo_vencimento_dias' => 'nullable|integer|min:1',
            'reposicao_dias'        => 'nullable|integer|min:1',
            'senha'                 => 'required|string|min:6',
            'beneficios'            => 'nullable|array',
        ]);

        return DB::transaction(function () use ($validated, $franquiaId) {
            $ultimo = Empresa::withTrashed()
                ->where('codigo', 'like', 'EM-%')
                ->orderByDesc('id')
                ->value('codigo');

            $numero = $ultimo ? (int) substr($ultimo, 3) + 1 : 1;
            $codigo = 'EM-' . str_pad($numero, 4, '0', STR_PAD_LEFT);

            $empresa = Empresa::create(array_merge(
                collect($validated)->except(['senha', 'beneficios'])->toArray(),
                [
                    'codigo'      => $codigo,
                    'franquia_id' => $franquiaId,
                    'active'      => true,
                    'status'      => 'aprovado',
                ]
            ));

            if (!empty($validated['beneficios'])) {
                $empresa->beneficios()->sync($validated['beneficios']);
            }

            $user = User::create([
                'name'     => $validated['razao_social'],
                'email'    => $validated['email'],
                'phone'    => $validated['telefone'] ?? null,
                'password' => Hash::make($validated['senha']),
                'active'   => true,
            ]);

            UserRole::create(['user_id' => $user->id, 'role' => 'empresa']);
            UserContext::create([
                'user_id'    => $user->id,
                'role'       => 'empresa',
                'context_id' => $empresa->id,
            ]);

            return response()->json([
                'message' => 'Empresa cadastrada com sucesso.',
                'empresa' => $empresa->load('beneficios'),
            ], 201);
        });
    }

    // GET /franquia/empresas/{id}
    public function show(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $empresa = Empresa::with(['vagas:id,empresa_id,titulo,status,created_at', 'taxasServico'])
            ->where('franquia_id', $franquiaId)
            ->findOrFail($id);

        return response()->json(['data' => $empresa]);
    }

    // PUT /franquia/empresas/{id}
    public function update(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $this->assertPremium($franquiaId);

        $empresa = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $validated = $request->validate([
            'razao_social'          => 'required|string|max:255',
            'nome_fantasia'         => 'nullable|string|max:255',
            'cnpj'                  => 'required|string|max:18|unique:empresas,cnpj,' . $empresa->id,
            'email'                 => 'required|email|max:255',
            'telefone'              => 'nullable|string|max:20',
            'cep'                   => 'nullable|string|max:9',
            'logradouro'            => 'nullable|string|max:255',
            'numero'                => 'nullable|string|max:20',
            'complemento'           => 'nullable|string|max:100',
            'bairro'                => 'nullable|string|max:100',
            'cidade'                => 'nullable|string|max:100',
            'estado'                => 'nullable|string|size:2',
            'descricao'             => 'nullable|string',
            'prazo_vencimento_dias' => 'nullable|integer|min:1',
            'reposicao_dias'        => 'nullable|integer|min:1',
            'nova_senha'            => 'nullable|string|min:6',
            'beneficios'            => 'nullable|array',
        ]);

        return DB::transaction(function () use ($validated, $empresa) {
            $empresa->update(collect($validated)->except(['nova_senha', 'beneficios'])->toArray());

            if (isset($validated['beneficios'])) {
                $empresa->beneficios()->sync($validated['beneficios']);
            }

            $user = $empresa->user();
            if ($user) {
                $userUpdate = [
                    'name'  => $validated['razao_social'],
                    'email' => $validated['email'],
                    'phone' => $validated['telefone'] ?? null,
                ];
                if (!empty($validated['nova_senha'])) {
                    $userUpdate['password'] = Hash::make($validated['nova_senha']);
                }
                $user->update($userUpdate);
            } elseif (!empty($validated['email']) && !empty($validated['nova_senha'])) {
                $newUser = User::create([
                    'name'     => $validated['razao_social'],
                    'email'    => $validated['email'],
                    'phone'    => $validated['telefone'] ?? null,
                    'password' => Hash::make($validated['nova_senha']),
                    'active'   => true,
                ]);
                UserRole::create(['user_id' => $newUser->id, 'role' => 'empresa']);
                UserContext::create([
                    'user_id'    => $newUser->id,
                    'role'       => 'empresa',
                    'context_id' => $empresa->id,
                ]);
            }

            return response()->json([
                'message' => 'Empresa atualizada.',
                'data'    => $empresa->fresh()->load('beneficios'),
            ]);
        });
    }

    // PATCH /franquia/empresas/{id}/toggle-active
    public function toggleActive(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $this->assertPremium($franquiaId);

        $empresa = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);
        $empresa->update(['active' => !$empresa->active]);

        return response()->json(['message' => 'Empresa atualizada.', 'active' => $empresa->active]);
    }

    // GET /franquia/empresas/{id}/followups
    public function indexFollowups(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresa    = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $followups = EmpresaFollowup::where('empresa_id', $empresa->id)
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $followups]);
    }

    // POST /franquia/empresas/{id}/followups
    public function storeFollowup(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresa    = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $request->validate(['mensagem' => 'required|string']);

        $followup = EmpresaFollowup::create([
            'empresa_id' => $empresa->id,
            'user_id'    => auth()->id(),
            'user_name'  => Franquia::find($franquiaId)?->nome ?? auth()->user()->name,
            'user_type'  => 'franquia',
            'mensagem'   => $request->mensagem,
        ]);

        return response()->json($followup, 201);
    }

    // PUT /franquia/empresas/{id}/followups/{followupId}
    public function updateFollowup(Request $request, int $id, int $followupId)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresa    = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $request->validate(['mensagem' => 'required|string']);

        $followup = EmpresaFollowup::where('empresa_id', $empresa->id)->findOrFail($followupId);
        $followup->update(['mensagem' => $request->mensagem]);

        return response()->json($followup);
    }

    // GET /franquia/empresas/{id}/beneficios
    public function indexBeneficios(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresa    = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        return response()->json(['data' => $empresa->beneficios()->get()]);
    }

    // GET /franquia/empresas/{id}/documentos
    public function indexDocumentos(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresa    = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $documentos = EmpresaArquivo::where('empresa_id', $empresa->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $documentos]);
    }

    // POST /franquia/empresas/{id}/documentos
    public function storeDocumento(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresa    = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $request->validate(['arquivo' => 'required|file|max:10240']);

        $file = $request->file('arquivo');
        $path = $file->store('empresas/documentos-agencia', 'public');

        $documento = EmpresaArquivo::create([
            'empresa_id'   => $empresa->id,
            'franquia_id'  => $franquiaId,
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => (int) round($file->getSize() / 1024),
        ]);

        return response()->json($documento, 201);
    }

    // DELETE /franquia/empresas/{id}/documentos/{docId}
    public function destroyDocumento(Request $request, int $id, int $docId)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresa    = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $documento = EmpresaArquivo::where('empresa_id', $empresa->id)->findOrFail($docId);
        Storage::disk('public')->delete($documento->arquivo_path);
        $documento->delete();

        return response()->json(['message' => 'Documento removido.']);
    }

    // POST /franquia/empresas/{id}/reset-password
    public function resetPassword(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $this->assertPremium($franquiaId);

        $empresa = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $request->validate(['password' => 'required|string|min:6']);

        $user = $empresa->user();
        if (!$user) {
            abort(422, 'Esta empresa não possui usuário de acesso vinculado.');
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }
}
