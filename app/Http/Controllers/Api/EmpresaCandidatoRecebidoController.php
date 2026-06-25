<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Envio;
use App\Models\EnvioParecer;
use App\Models\KanbanEtapa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmpresaCandidatoRecebidoController extends Controller
{
    use HasTokenContext;

    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $envios = $this->baseQuery($empresaId)
            ->when($request->filled('origem'), fn($q) => $q->where('envios.origem', $request->origem))
            ->when($request->filled('vaga_id'), fn($q) => $q->where('envios.vaga_id', $request->vaga_id))
            ->when($request->filled('kanban_etapa_id'), fn($q) => $q->where('envios.kanban_etapa_id', $request->kanban_etapa_id))
            ->when($request->filled('status'), fn($q) => $q->where('envios.status_empresa', $request->status))
            ->when($request->filled('periodo_inicio'), fn($q) => $q->whereDate('envios.created_at', '>=', $request->periodo_inicio))
            ->when($request->filled('periodo_fim'), fn($q) => $q->whereDate('envios.created_at', '<=', $request->periodo_fim))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->whereHas('candidato', function ($sub) use ($s) {
                    $sub->where('telefone', 'like', "%{$s}%")
                        ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
                });
            })
            ->orderByDesc('envios.created_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($envios->items())->map(fn($e) => $this->payload($e)),
            'meta' => ['current_page' => $envios->currentPage(), 'last_page' => $envios->lastPage(),
                       'per_page' => $envios->perPage(), 'total' => $envios->total()],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $envio = $this->baseQuery($empresaId)
            ->with('pareceres')
            ->findOrFail($id);

        $parecer = $envio->pareceres->sortByDesc('created_at')->first();

        return response()->json(['data' => [
            ...$this->payload($envio),
            'mensagem'         => $envio->mensagem,
            'historico_etapa'  => [], // reservado para evolução futura
            'parecer'          => $parecer ? [
                'id'         => $parecer->id,
                'texto'      => $parecer->texto,
                'autor'      => $parecer->autor,
                'created_at' => $parecer->created_at,
            ] : null,
        ]]);
    }

    public function updateEtapa(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate(['kanban_etapa_id' => 'required|integer|exists:kanban_etapas,id']);

        // etapa precisa ser global ou da própria empresa
        $etapa = KanbanEtapa::where('id', $data['kanban_etapa_id'])
            ->where(fn($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $empresaId))
            ->firstOrFail();

        $envio = $this->baseQuery($empresaId)->findOrFail($id);
        $envio->update([
            'kanban_etapa_id' => $etapa->id,
            'status'          => 'em_processo', // status visível ao candidato
        ]);

        return response()->json([
            'message' => 'Etapa atualizada.',
            'envio'   => ['id' => $envio->id, 'kanban_etapa_id' => $etapa->id, 'status' => 'em_processo'],
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate([
            'status'           => 'required|in:pendente,aprovado,reprovado,desistiu,reposicao',
            'observacao'       => 'nullable|string',
            'salario_aprovado' => 'nullable|numeric|min:0',
            'data_admissao'    => 'nullable|date',
            'data_saida'       => 'nullable|date',
        ]);

        $envio = $this->baseQuery($empresaId)->findOrFail($id);

        $envio->update([
            'status_empresa'   => $data['status'],
            'observacao'       => $data['observacao'] ?? $envio->observacao,
            'salario_aprovado' => $data['salario_aprovado'] ?? $envio->salario_aprovado,
            'data_admissao'    => $data['data_admissao'] ?? $envio->data_admissao,
            'data_saida'       => $data['data_saida'] ?? $envio->data_saida,
            // reflete no status que o candidato enxerga
            'status'           => match ($data['status']) {
                'aprovado'  => 'aprovado',
                'reprovado' => 'reprovado',
                default     => $envio->status,
            },
        ]);

        return response()->json(['message' => 'Status atualizado.']);
    }

    public function storeParecer(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate([
            'texto'   => 'required|string',
            'autor'   => 'nullable|string|max:255',
            'arquivo' => 'nullable|file|max:5120|mimes:pdf',
        ]);

        $envio = $this->baseQuery($empresaId)->with('vaga:id,requer_validacao_premium')->findOrFail($id);

        $arquivoPath = null;
        $arquivoNome = null;
        if ($request->hasFile('arquivo')) {
            $arquivoPath = $request->file('arquivo')->store("empresas/{$empresaId}/pareceres", 'public');
            $arquivoNome = $request->file('arquivo')->getClientOriginalName();
        }

        // Se a vaga exige validacao da franquia premium, o parecer fica pendente;
        // caso contrario e enviado direto.
        $status = $envio->vaga?->requer_validacao_premium ? 'pendente_validacao' : 'enviado';

        $parecer = EnvioParecer::create([
            'envio_id'     => $envio->id,
            'texto'        => $data['texto'],
            'autor'        => $data['autor'] ?? $request->user()->name,
            'arquivo_path' => $arquivoPath,
            'arquivo_nome' => $arquivoNome,
            'created_by'   => $request->user()->id,
            'status'       => $status,
        ]);

        return response()->json(['data' => [
            'id'         => $parecer->id,
            'texto'      => $parecer->texto,
            'autor'      => $parecer->autor,
            'status'     => $parecer->status,
            'created_at' => $parecer->created_at,
        ]], 201);
    }

    public function downloadCurriculo(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $envio = $this->baseQuery($empresaId)->with('curriculo')->findOrFail($id);

        $doc = $envio->curriculo;
        if (!$doc || !Storage::disk('public')->exists($doc->arquivo_path)) {
            return response()->json(['message' => 'Currículo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($doc->arquivo_path, $doc->arquivo_nome);
    }

    public function kanbanEtapas(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $etapas = KanbanEtapa::where(fn($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $empresaId))
            ->orderBy('ordem')
            ->get(['id', 'nome', 'cor', 'ordem', 'etapa_sistema']);

        return response()->json(['data' => $etapas]);
    }

    public function mapaCandidatos(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $candidatos = \App\Models\Candidato::with('user:id,name')
            ->whereHas('envios.vaga', fn($q) => $q->where('empresa_id', $empresaId))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(fn($c) => [
                'id'             => $c->id,
                'nome'           => $c->user?->name,
                'cargo_desejado' => $c->cargo_desejado,
                'cidade'         => $c->cidade,
                'estado'         => $c->estado,
                'telefone'       => $c->telefone,
                'latitude'       => (float) $c->latitude,
                'longitude'      => (float) $c->longitude,
            ]);

        return response()->json(['data' => $candidatos]);
    }

    /* ─── Helpers ────────────────────────────────────────────────────── */

    private function baseQuery(int $empresaId)
    {
        return Envio::with(['candidato.user:id,name,email', 'vaga:id,titulo,salario_min,salario_max', 'kanbanEtapa:id,nome'])
            ->whereHas('vaga', fn($q) => $q->where('empresa_id', $empresaId));
    }

    private function payload(Envio $e): array
    {
        return [
            'id'                => $e->id,
            'curriculo_id'      => $e->curriculo_id,
            'vaga_id'           => $e->vaga_id,
            'kanban_etapa_id'   => $e->kanban_etapa_id,
            'kanban_etapa_nome' => $e->kanbanEtapa?->nome ?? 'Recebido',
            'origem'            => $e->origem,
            'status'            => $e->status_empresa,
            'observacao'        => $e->observacao,
            'salario_aprovado'  => $e->salario_aprovado,
            'data_admissao'     => $e->data_admissao?->toDateString(),
            'data_saida'        => $e->data_saida?->toDateString(),
            'created_at'        => $e->created_at,
            'candidato'         => $e->candidato ? [
                'id'       => $e->candidato->id,
                'nome'     => $e->candidato->user?->name,
                'email'    => $e->candidato->user?->email,
                'telefone' => $e->candidato->telefone,
                'cidade'   => $e->candidato->cidade,
                'estado'   => $e->candidato->estado,
            ] : null,
            'vaga'              => $e->vaga ? [
                'id'          => $e->vaga->id,
                'titulo'      => $e->vaga->titulo,
                'salario_min' => $e->vaga->salario_min,
                'salario_max' => $e->vaga->salario_max,
            ] : null,
        ];
    }
}
