<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaArquivo;
use App\Models\EmpresaFollowup;
use App\Models\Envio;
use App\Models\Franquia;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserContext;
use App\Models\Vaga;
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

    // GET /franquia/empresas/relatorios
    public function relatorios(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresas   = Empresa::where('franquia_id', $franquiaId)->get();
        $empresaIds = $empresas->pluck('id');
        $vagaIds    = Vaga::whereIn('empresa_id', $empresaIds)->pluck('id');

        $mesesAbrev = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        $meses = collect(range(5, 0))->map(fn($i) => now()->subMonths($i));

        $candidatosPorMes = $meses->map(fn($m) => [
            'mes'        => ucfirst($mesesAbrev[$m->month - 1]),
            'candidatos' => Envio::whereIn('vaga_id', $vagaIds)
                ->whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->count(),
        ])->values();

        $vagasPorMes = $meses->map(fn($m) => [
            'mes'   => ucfirst($mesesAbrev[$m->month - 1]),
            'vagas' => Vaga::whereIn('empresa_id', $empresaIds)
                ->whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->count(),
        ])->values();

        $tabela = $empresas->map(function ($e) {
            $vagaIdsEmpresa = Vaga::where('empresa_id', $e->id)->pluck('id');
            return [
                'nome'       => $e->razao_social,
                'vagas'      => $vagaIdsEmpresa->count(),
                'candidatos' => Envio::whereIn('vaga_id', $vagaIdsEmpresa)->count(),
                'status'     => $e->active ? 'ativo' : 'inativo',
            ];
        })->values();

        return response()->json(['data' => [
            'kpis' => [
                'total_empresas'        => $empresas->count(),
                'empresas_ativas'       => $empresas->where('active', true)->count(),
                'vagas_abertas'         => Vaga::whereIn('empresa_id', $empresaIds)->where('status', 'publicada')->count(),
                'candidatos_recebidos'  => Envio::whereIn('vaga_id', $vagaIds)->count(),
            ],
            'candidatos_por_mes' => $candidatosPorMes,
            'vagas_por_mes'      => $vagasPorMes,
            'empresas'           => $tabela,
        ]]);
    }

    // GET /franquia/empresas
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $isPremium  = Franquia::find($franquiaId)?->tipo === 'premium';

        $query = Empresa::with('franquia:id,nome,tipo,telefone,email,email_franqueado')
            ->withCount('vagas as total_vagas');

        if ($request->boolean('all')) {
            $query->where('active', true);
        } elseif ($request->boolean('minhas') || !$isPremium) {
            // Franquias start sempre veem só as próprias empresas; premium só quando
            // pede explicitamente a aba "Minhas Empresas" (as demais empresas do
            // sistema aparecem em "Todas as Empresas", sem esse filtro).
            $query->where('franquia_id', $franquiaId);
        }

        if ($request->filled('status')) {
            $query->where('active', $request->status === 'ativa');
        }

        $perPage = $request->integer('per_page', 20);
        $empresas = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $empresas->getCollection()->map(fn($e) => [
                'id'           => $e->id,
                'codigo'       => $e->codigo,
                'razao_social' => $e->razao_social,
                'nome_fantasia'=> $e->nome_fantasia,
                'cnpj'         => $e->cnpj,
                'tipo_empresa' => $e->tipo_empresa,
                'tipo_acesso'  => $e->tipo_acesso,
                'franquia'     => $e->franquia ? [
                    'id'       => $e->franquia->id,
                    'nome'     => $e->franquia->nome,
                    'tipo'     => $e->franquia->tipo,
                    'telefone' => $e->franquia->telefone,
                    'email'    => $e->franquia->email_franqueado ?? $e->franquia->email,
                ] : null,
                'cidade'       => $e->cidade,
                'estado'       => $e->estado,
                'email'        => $e->email,
                'telefone'     => $e->telefone,
                'active'       => $e->active,
                'total_vagas'  => $e->total_vagas,
                'created_at'   => $e->created_at,
                'is_owner'     => $e->franquia_id === $franquiaId,
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
            // unique em users: o e-mail vira o login da empresa
            'email'                 => 'required|email|max:255|unique:users,email',
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
                collect($validated)->except(['senha', 'beneficios', 'logradouro'])->toArray(),
                [
                    // A coluna da tabela é `rua`; o formulário envia `logradouro`
                    'rua'         => $validated['logradouro'] ?? null,
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
    //
    // Leitura não é restrita à própria franquia: uma vaga (inclusive as criadas
    // pelo admin) pode estar associada a uma empresa de outra franquia, e a tela
    // de editar/visualizar vaga precisa desses dados (endereço, taxas de serviço
    // por nível) mesmo quando quem está vendo não é dono da empresa. As ações de
    // escrita (update/toggleActive/resetPassword/destroy) continuam restritas.
    public function show(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $empresa = Empresa::with(['vagas:id,empresa_id,titulo,status,created_at', 'taxasServico'])
            ->findOrFail($id);

        return response()->json(['data' => [
            ...$empresa->toArray(),
            'is_owner' => $empresa->franquia_id === $franquiaId,
        ]]);
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
            $dados = collect($validated)->except(['nova_senha', 'beneficios', 'logradouro'])->toArray();
            // A coluna da tabela é `rua`; o formulário envia `logradouro`
            if (array_key_exists('logradouro', $validated)) {
                $dados['rua'] = $validated['logradouro'];
            }
            $empresa->update($dados);

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
    // GET /franquia/empresas/{id}/faturamentos — consulta (somente leitura)
    public function indexFaturamentos(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresa    = Empresa::where('franquia_id', $franquiaId)->findOrFail($id);

        $faturamentos = \App\Models\EmpresaFaturamento::where('empresa_id', $empresa->id)
            ->orderByDesc('ano')
            ->orderByDesc('mes')
            ->get();

        return response()->json(['data' => $faturamentos]);
    }

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
