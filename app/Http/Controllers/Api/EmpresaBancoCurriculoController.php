<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\CandidatoDocumento;
use App\Models\Empresa;
use App\Models\EmpresaCurriculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmpresaBancoCurriculoController extends Controller
{
    use HasTokenContext;

    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        // Ingere no banco interno os candidatos que aplicaram em vagas da empresa
        $this->ingerirEnvios($empresaId);

        $curriculos = EmpresaCurriculo::where('empresa_id', $empresaId)
            ->when($request->filled('q'), function ($query) use ($request) {
                $s = $request->q;
                $query->where(fn($q) => $q->where('nome', 'like', "%{$s}%")
                    ->orWhere('cargo_desejado', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%"));
            })
            ->when($request->filled('cidade'), fn($q) => $q->where('cidade', 'like', "%{$request->cidade}%"))
            ->when($request->filled('estado'), fn($q) => $q->where('estado', $request->estado))
            ->when($request->filled('origem'), fn($q) => $q->where('origem', $request->origem))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($curriculos->items())->map(fn($c) => $this->payload($c)),
            'meta' => ['current_page' => $curriculos->currentPage(), 'last_page' => $curriculos->lastPage(),
                       'per_page' => $curriculos->perPage(), 'total' => $curriculos->total()],
        ]);
    }

    public function store(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        // O front envia "nome_completo"; o contrato original previa "nome" — aceita ambos
        if (!$request->filled('nome') && $request->filled('nome_completo')) {
            $request->merge(['nome' => $request->nome_completo]);
        }

        $data = $request->validate([
            'nome'                     => 'required|string|max:255',
            'email'                    => 'nullable|email|max:255',
            'telefone'                 => 'nullable|string|max:20',
            'cpf'                      => 'nullable|string|max:14',
            'cargo_desejado'           => 'nullable|string|max:255',
            'cargos_interesse'         => 'nullable|array',
            'cargos_interesse.*'       => 'string|max:100',
            'experiencia_profissional' => 'nullable|string',
            'educacao'                 => 'nullable|string',
            'habilidades'              => 'nullable|string',
            'cidade'                   => 'nullable|string|max:100',
            'estado'                   => 'nullable|string|size:2',
            'bairro'                   => 'nullable|string|max:100',
            'cep'                      => 'nullable|string|max:9',
            'rua'                      => 'nullable|string|max:255',
            'numero'                   => 'nullable|string|max:20',
            'complemento'              => 'nullable|string|max:100',
            'tipo_cnh'                 => 'nullable|string|max:10',
            'informacoes_pessoais'     => 'nullable|string',
            'idiomas'                  => 'nullable|string|max:500',
            'informacoes_adicionais'   => 'nullable|string',
            'status'                   => 'nullable|in:ativo,inativo',
            'origem'                   => 'nullable|in:manual,copia_base',
            'arquivo'                  => 'nullable|file|max:10240|mimes:pdf,doc,docx',
        ]);

        $extra = [
            'empresa_id' => $empresaId,
            'origem'     => $data['origem'] ?? 'manual',
            'active'     => ($data['status'] ?? 'ativo') === 'ativo',
        ];

        if ($request->hasFile('arquivo')) {
            $arquivo = $request->file('arquivo');
            $extra['arquivo_path'] = $arquivo->store("empresas/{$empresaId}/banco-curriculos", 'public');
            $extra['arquivo_nome'] = $arquivo->getClientOriginalName();
        }

        $curriculo = EmpresaCurriculo::create([
            ...collect($data)->except(['arquivo', 'origem', 'status'])->all(),
            ...$extra,
        ]);

        return response()->json(['data' => $this->payload($curriculo)], 201);
    }

    public function copiaBase(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $empresa = Empresa::find($empresaId);
        if ($empresa && $empresa->plano === 'basico') {
            return response()->json([
                'message'    => 'Cópia do banco da franquia disponível apenas no Plano Padrão/Premium.',
                'upgrade_to' => 'padrao',
            ], 402);
        }

        $data = $request->validate(['curriculo_id' => 'required|integer|exists:candidato_documentos,id']);

        $doc = CandidatoDocumento::with('candidato.user')->findOrFail($data['curriculo_id']);
        $candidato = $doc->candidato;

        $curriculo = EmpresaCurriculo::updateOrCreate(
            ['empresa_id' => $empresaId, 'candidato_id' => $candidato->id],
            [
                'nome'           => $candidato->user?->name ?? 'Candidato',
                'email'          => $candidato->user?->email,
                'telefone'       => $candidato->telefone,
                'cpf'            => $candidato->cpf,
                'cargo_desejado' => $candidato->cargo_desejado,
                'cidade'         => $candidato->cidade,
                'estado'         => $candidato->estado,
                'origem'         => 'copia_base',
                'arquivo_path'   => $doc->arquivo_path,
                'arquivo_nome'   => $doc->arquivo_nome,
            ],
        );

        return response()->json(['data' => $this->payload($curriculo)], 201);
    }

    public function update(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        if (!$request->filled('nome') && $request->filled('nome_completo')) {
            $request->merge(['nome' => $request->nome_completo]);
        }

        $data = $request->validate([
            'nome'                     => 'required|string|max:255',
            'email'                    => 'nullable|email|max:255',
            'telefone'                 => 'nullable|string|max:20',
            'cpf'                      => 'nullable|string|max:14',
            'cargo_desejado'           => 'nullable|string|max:255',
            'cargos_interesse'         => 'nullable|array',
            'cargos_interesse.*'       => 'string|max:100',
            'experiencia_profissional' => 'nullable|string',
            'educacao'                 => 'nullable|string',
            'habilidades'              => 'nullable|string',
            'cidade'                   => 'nullable|string|max:100',
            'estado'                   => 'nullable|string|size:2',
            'bairro'                   => 'nullable|string|max:100',
        ]);

        $curriculo = EmpresaCurriculo::where('empresa_id', $empresaId)->findOrFail($id);
        $curriculo->update($data);

        return response()->json(['message' => 'Currículo atualizado.']);
    }

    public function destroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $curriculo = EmpresaCurriculo::where('empresa_id', $empresaId)->findOrFail($id);

        if ($curriculo->arquivo_path && $curriculo->origem === 'manual') {
            Storage::disk('public')->delete($curriculo->arquivo_path);
        }

        $curriculo->delete();

        return response()->noContent();
    }

    public function updateEtapa(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate(['kanban_etapa_id' => 'required|integer|exists:kanban_etapas,id']);

        $curriculo = EmpresaCurriculo::where('empresa_id', $empresaId)->findOrFail($id);
        $curriculo->update($data);

        return response()->json([
            'message'   => 'Etapa atualizada.',
            'curriculo' => ['id' => $curriculo->id, 'kanban_etapa_id' => $curriculo->kanban_etapa_id],
        ]);
    }

    public function duplicata(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $request->validate([
            'nome'  => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'cpf'   => 'nullable|string|max:14',
        ]);

        if (!$request->filled('email') && !$request->filled('cpf') && !$request->filled('nome')) {
            return response()->json(['duplicado' => false]);
        }

        $existente = EmpresaCurriculo::where('empresa_id', $empresaId)
            ->where(function ($q) use ($request) {
                if ($request->filled('email')) $q->orWhere('email', $request->email);
                if ($request->filled('cpf'))   $q->orWhere('cpf', $request->cpf);
                if ($request->filled('nome'))  $q->orWhere('nome', $request->nome);
            })
            ->first();

        return response()->json([
            'duplicado'    => (bool) $existente,
            'curriculo_id' => $existente?->id,
        ]);
    }

    public function download(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $curriculo = EmpresaCurriculo::where('empresa_id', $empresaId)->findOrFail($id);

        if (!$curriculo->arquivo_path || !Storage::disk('public')->exists($curriculo->arquivo_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($curriculo->arquivo_path, $curriculo->arquivo_nome);
    }

    /* ─── Helpers ────────────────────────────────────────────────────── */

    /**
     * Garante que todo candidato com envio para vagas da empresa
     * tenha registro no banco de currículos (origem plataforma).
     */
    private function ingerirEnvios(int $empresaId): void
    {
        try {
            DB::statement("
                INSERT IGNORE INTO empresa_curriculos
                    (empresa_id, candidato_id, nome, email, telefone, cpf, cargo_desejado,
                     cidade, estado, origem, arquivo_path, arquivo_nome, created_at, updated_at)
                SELECT ?, c.id, u.name, u.email, c.telefone, c.cpf, c.cargo_desejado,
                       c.cidade, c.estado, 'plataforma', cd.arquivo_path, cd.arquivo_nome, NOW(), NOW()
                FROM (
                    SELECT MAX(e.id) AS envio_id, e.candidato_id
                    FROM envios e
                    JOIN vagas v ON v.id = e.vaga_id AND v.empresa_id = ?
                    GROUP BY e.candidato_id
                ) ult
                JOIN envios e              ON e.id = ult.envio_id
                JOIN candidatos c          ON c.id = ult.candidato_id
                JOIN users u               ON u.id = c.user_id
                LEFT JOIN candidato_documentos cd ON cd.id = e.curriculo_id
            ", [$empresaId, $empresaId]);
        } catch (\Throwable) {
            // ingestão é best-effort; a listagem segue com o que existir
        }
    }

    private function payload(EmpresaCurriculo $c): array
    {
        return [
            'id'                       => $c->id,
            'candidato_id'             => $c->candidato_id,
            'kanban_etapa_id'          => $c->kanban_etapa_id,
            'nome'                     => $c->nome,
            'nome_completo'            => $c->nome, // alias usado pelo front
            'email'                    => $c->email,
            'telefone'                 => $c->telefone,
            'cargo_desejado'           => $c->cargo_desejado,
            'cargos_interesse'         => $c->cargos_interesse ?? [],
            'experiencia_profissional' => $c->experiencia_profissional,
            'educacao'                 => $c->educacao,
            'habilidades'              => $c->habilidades,
            'cidade'                   => $c->cidade,
            'estado'                   => $c->estado,
            'bairro'                   => $c->bairro,
            'cep'                      => $c->cep,
            'rua'                      => $c->rua,
            'numero'                   => $c->numero,
            'complemento'              => $c->complemento,
            'tipo_cnh'                 => $c->tipo_cnh,
            'informacoes_pessoais'     => $c->informacoes_pessoais,
            'idiomas'                  => $c->idiomas,
            'informacoes_adicionais'   => $c->informacoes_adicionais,
            'status'                   => $c->active ? 'ativo' : 'inativo',
            'origem'                   => $c->origem,
            'arquivo_nome'             => $c->arquivo_nome,
            'created_at'               => $c->created_at,
        ];
    }
}
