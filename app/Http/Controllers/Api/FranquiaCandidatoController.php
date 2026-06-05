<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoParecer;
use App\Models\Envio;
use App\Models\Vaga;
use Illuminate\Http\Request;

class FranquiaCandidatoController extends Controller
{
    use HasTokenContext;

    private function vagaIds(int $franquiaId): \Illuminate\Support\Collection
    {
        return Vaga::where('franquia_id', $franquiaId)->pluck('id');
    }

    // GET /franquia/candidatos
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $query = Candidato::with('user:id,name,email')
            ->whereHas('envios', fn($q) => $q->whereIn('vaga_id', $vagaIds))
            ->where('active', true);

        if ($request->filled('cargo')) {
            $query->where('cargo_desejado', 'like', '%' . $request->cargo . '%');
        }
        if ($request->filled('cidade')) {
            $query->where('cidade', 'like', '%' . $request->cidade . '%');
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $perPage     = min((int) $request->query('per_page', 20), 100);
        $candidatos  = $query->orderByDesc('created_at')->paginate($perPage);

        $items = $candidatos->getCollection()->map(fn($c) => [
            'id'               => $c->id,
            'nome'             => $c->user?->name,
            'cpf'              => $c->cpf,
            'cargo_desejado'   => $c->cargo_desejado,
            'cidade'           => $c->cidade,
            'estado'           => $c->estado,
            'disponibilidade'  => $c->disponibilidade,
            'curriculo_ativo'  => null, // carregado on-demand no show
            'ultimo_envio'     => $c->envios()->whereIn('vaga_id', $vagaIds)->latest()->value('created_at'),
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $candidatos->total(),
                'per_page'     => $candidatos->perPage(),
                'current_page' => $candidatos->currentPage(),
                'last_page'    => $candidatos->lastPage(),
            ],
        ]);
    }

    // GET /franquia/candidatos/status  (kanban)
    public function status(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $envios = Envio::with(['candidato.user:id,name', 'vaga:id,titulo'])
            ->whereIn('vaga_id', $vagaIds)
            ->get();

        $grouped = $envios->groupBy('status');
        $statusKeys = ['enviado', 'visualizado', 'em_processo', 'aprovado', 'reprovado'];

        $data = [];
        foreach ($statusKeys as $key) {
            $data[$key] = ($grouped[$key] ?? collect())->map(fn($e) => [
                'candidato_id' => $e->candidato_id,
                'nome'         => $e->candidato?->user?->name,
                'vaga'         => $e->vaga?->titulo,
                'vaga_id'      => $e->vaga_id,
            ])->values();
        }

        return response()->json(['data' => $data]);
    }

    // GET /franquia/candidatos/pareceres
    public function pareceres(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $pareceres = CandidatoParecer::with(['candidato.user:id,name', 'vaga:id,titulo'])
            ->where('franquia_id', $franquiaId)
            ->orderByDesc('created_at')
            ->paginate(20);

        $items = $pareceres->getCollection()->map(fn($p) => [
            'id'        => $p->id,
            'candidato' => ['id' => $p->candidato_id, 'nome' => $p->candidato?->user?->name],
            'vaga'      => $p->vaga ? ['id' => $p->vaga_id, 'titulo' => $p->vaga->titulo] : null,
            'texto'     => $p->texto,
            'nota'      => $p->nota,
            'created_at'=> $p->created_at,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $pareceres->total(),
                'per_page'     => $pareceres->perPage(),
                'current_page' => $pareceres->currentPage(),
                'last_page'    => $pareceres->lastPage(),
            ],
        ]);
    }

    // GET /franquia/candidatos/{id}
    public function show(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $candidato = Candidato::with([
            'user:id,name,email',
            'documentos' => fn($q) => $q->where('ativo', true)->limit(1),
        ])
        ->whereHas('envios', fn($q) => $q->whereIn('vaga_id', $vagaIds))
        ->findOrFail($id);

        $candidaturas = Envio::with('vaga:id,titulo')
            ->where('candidato_id', $candidato->id)
            ->whereIn('vaga_id', $vagaIds)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($e) => [
                'vaga_id' => $e->vaga_id,
                'titulo'  => $e->vaga?->titulo,
                'status'  => $e->status,
                'updated_at' => $e->updated_at,
            ]);

        $curriculo = $candidato->documentos->first();

        return response()->json(['data' => [
            'id'                       => $candidato->id,
            'nome'                     => $candidato->user?->name,
            'email'                    => $candidato->user?->email,
            'telefone'                 => $candidato->telefone,
            'cpf'                      => $candidato->cpf,
            'nascimento'               => $candidato->data_nascimento,
            'cargo_desejado'           => $candidato->cargo_desejado,
            'cidade'                   => $candidato->cidade,
            'estado'                   => $candidato->estado,
            'disponibilidade'          => $candidato->disponibilidade,
            'curriculo_ativo'          => $curriculo ? ['id' => $curriculo->id, 'arquivo_nome' => $curriculo->arquivo_nome] : null,
            'candidaturas'             => $candidaturas,
        ]]);
    }

    // PUT /franquia/candidatos/{id}
    public function update(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $candidato = Candidato::whereHas('envios', fn($q) => $q->whereIn('vaga_id', $vagaIds))
            ->findOrFail($id);

        $validated = $request->validate([
            'cargo_desejado'            => 'nullable|string|max:100',
            'telefone'                  => 'nullable|string|max:20',
            'cep'                       => 'nullable|string|max:9',
            'bairro'                    => 'nullable|string|max:100',
            'rua'                       => 'nullable|string|max:255',
            'numero'                    => 'nullable|string|max:20',
            'cidade'                    => 'nullable|string|max:100',
            'estado'                    => 'nullable|string|size:2',
            'informacoes_pessoais'      => 'nullable|string',
            'experiencia_profissional'  => 'nullable|string',
            'educacao'                  => 'nullable|string',
            'habilidades'               => 'nullable|string',
            'informacoes_adicionais'    => 'nullable|string',
        ]);

        $candidato->update($validated);

        return response()->json(['message' => 'Candidato atualizado.', 'data' => $candidato->fresh()]);
    }

    // POST /franquia/candidatos/{candidatoId}/vincular
    public function vincular(Request $request, int $candidatoId)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $request->validate(['vaga_id' => 'required|integer']);

        if (!$vagaIds->contains($request->vaga_id)) {
            return response()->json(['message' => 'Vaga não pertence a esta franquia.'], 403);
        }

        $candidato = Candidato::findOrFail($candidatoId);
        $curriculo = $candidato->documentos()->where('ativo', true)->first()
            ?? $candidato->documentos()->latest()->first();

        if (!$curriculo) {
            return response()->json(['message' => 'Candidato não possui currículo cadastrado.'], 422);
        }

        $envio = Envio::firstOrCreate(
            ['candidato_id' => $candidato->id, 'vaga_id' => $request->vaga_id],
            ['curriculo_id' => $curriculo->id, 'status' => 'enviado']
        );

        return response()->json([
            'message' => 'Candidato vinculado com sucesso.',
            'data'    => ['candidato_id' => $candidato->id, 'vaga_id' => $request->vaga_id, 'status' => $envio->status],
        ], 201);
    }

    // GET /franquia/candidatos/{id}/historico
    public function historico(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $envios = Envio::with('vaga:id,titulo')
            ->where('candidato_id', $id)
            ->whereIn('vaga_id', $vagaIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($e) => [
                'id'          => $e->id,
                'vaga_nome'   => $e->vaga?->titulo,
                'franquia'    => null,
                'status'      => $e->status,
                'vinculado_em'=> $e->created_at,
            ]);

        return response()->json(['data' => $envios]);
    }

    // GET /franquia/candidatos/{id}/pareceres
    public function pareceresCandidato(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $pareceres = CandidatoParecer::with(['criador:id,name', 'vaga:id,titulo', 'franquia:id,nome'])
            ->where('candidato_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) use ($franquiaId) {
                $isOwn = $p->franquia_id === $franquiaId;
                return [
                    'id'                => $p->id,
                    'parecer'           => $isOwn ? $p->texto : '[Conteúdo restrito à franquia de origem]',
                    'nota'              => $isOwn ? $p->nota : null,
                    'cargo_pretendido'  => $p->vaga?->titulo,
                    'criado_por_nome'   => $p->criador?->name,
                    'franquia_nome'     => $p->franquia?->nome ?? 'Outra Franquia',
                    'is_own'            => $isOwn,
                    'created_at'        => $p->created_at,
                ];
            });

        return response()->json(['data' => $pareceres]);
    }

    // POST /franquia/candidatos/{id}/parecer
    public function storeParecer(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $validated = $request->validate([
            'vaga_id' => 'nullable|integer|exists:vagas,id',
            'texto'   => 'required|string|max:5000',
            'nota'    => 'nullable|integer|min:1|max:5',
        ]);

        $parecer = CandidatoParecer::create([
            'franquia_id' => $franquiaId,
            'candidato_id'=> $id,
            'vaga_id'     => $validated['vaga_id'] ?? null,
            'criado_por'  => $request->user()->id,
            'texto'       => $validated['texto'],
            'nota'        => $validated['nota'] ?? null,
        ]);

        return response()->json([
            'message' => 'Parecer registrado.',
            'data'    => ['id' => $parecer->id, 'nota' => $parecer->nota],
        ], 201);
    }

    // PUT /franquia/candidatos/parecer/{id}
    public function updateParecer(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $parecer = CandidatoParecer::where('franquia_id', $franquiaId)->findOrFail($id);

        $validated = $request->validate([
            'texto' => 'required|string|max:5000',
            'nota'  => 'nullable|integer|min:1|max:5',
        ]);

        $parecer->update([
            'texto' => $validated['texto'],
            'nota'  => $validated['nota'] ?? null,
        ]);

        return response()->json([
            'message' => 'Parecer atualizado com sucesso.',
            'data'    => $parecer,
        ]);
    }

    // DELETE /franquia/candidatos/parecer/{id}
    public function destroyParecer(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $parecer = CandidatoParecer::where('franquia_id', $franquiaId)->findOrFail($id);
        $parecer->delete();

        return response()->json([
            'message' => 'Parecer excluído com sucesso.',
        ]);
    }

    // GET /franquia/candidatos/{id}/disc
    public function disc(Request $request, int $id)
    {
        $disc = \App\Models\CandidatoDisc::where('candidato_id', $id)
            ->with('aplicador:id,name')
            ->latest()
            ->first();

        if (!$disc) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'perfil_dominante'  => $disc->perfil_dominante,
            'score_d'           => $disc->score_d,
            'score_i'           => $disc->score_i,
            'score_s'           => $disc->score_s,
            'score_c'           => $disc->score_c,
            'aplicado_por_nome' => $disc->aplicador?->name,
            'created_at'        => $disc->created_at,
        ]]);
    }

    // PATCH /franquia/candidatos/{candidatoId}/vagas/{vagaId}/status
    public function updateStatus(Request $request, int $candidatoId, int $vagaId)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        if (!$vagaIds->contains($vagaId)) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }

        $request->validate([
            'status' => 'required|in:enviado,visualizado,em_processo,aprovado,reprovado',
        ]);

        $envio = Envio::where('candidato_id', $candidatoId)
            ->where('vaga_id', $vagaId)
            ->firstOrFail();

        $envio->update(['status' => $request->status]);

        return response()->json(['message' => 'Status atualizado.', 'status' => $request->status]);
    }
}
