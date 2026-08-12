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
            'status'           => 'required|in:pendente,em_processo,aprovado,reprovado,desistiu,reposicao',
            'observacao'       => 'nullable|string',
            'salario_aprovado' => 'nullable|numeric|min:0',
            'tipo_contrato'    => 'nullable|string|max:50',
            'data_admissao'    => 'nullable|date',
            'data_saida'       => 'nullable|date',
        ]);

        $envio = $this->baseQuery($empresaId)->findOrFail($id);

        $envio->fill([
            'status_empresa' => $data['status'],
            // Mesmo processo seletivo: reflete para a franquia e para o candidato
            'status'         => Envio::statusFranquiaPara($data['status'], $envio->status),
        ]);

        // Demais campos apenas quando enviados — mesma regra do painel da franquia
        foreach (['observacao', 'salario_aprovado', 'tipo_contrato', 'data_admissao', 'data_saida'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $envio->{$campo} = $data[$campo];
            }
        }

        if ($data['status'] === 'aprovado' && empty($envio->data_admissao)) {
            $envio->data_admissao = now()->toDateString();
        }

        $envio->save();

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

        $envio = $this->baseQuery($empresaId)->with('vaga:id,requer_validacao_premium,canal')->findOrFail($id);

        // Vaga do produto Agência: o parecer é responsabilidade da franquia,
        // que conduz o recrutamento. A empresa acompanha, mas não emite.
        if ($envio->vaga?->canal === 'agencia') {
            return response()->json([
                'message' => 'Esta vaga é conduzida pela agência: o parecer do candidato é emitido pela franquia responsável.',
            ], 403);
        }

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

        // O mapa reflete o BANCO DE CURRÍCULOS da empresa (não apenas quem se
        // candidatou). Coordenadas próprias do currículo têm prioridade; se
        // ainda não foram geocodificadas, cai nas do cadastro do candidato.
        $candidatos = \App\Models\EmpresaCurriculo::with('candidato:id,latitude,longitude')
            ->where('empresa_id', $empresaId)
            ->where(function ($q) {
                $q->whereNotNull('latitude')
                  ->orWhereHas('candidato', fn($c) => $c->whereNotNull('latitude'));
            })
            ->get()
            ->map(function ($c) {
                $lat = $c->latitude ?? $c->candidato?->latitude;
                $lng = $c->longitude ?? $c->candidato?->longitude;

                if ($lat === null || $lng === null) {
                    return null;
                }

                return [
                    'id'             => $c->id,
                    'candidato_id'   => $c->candidato_id,
                    'nome'           => $c->nome,
                    'cargo_desejado' => $c->cargo_desejado,
                    'cidade'         => $c->cidade,
                    'estado'         => $c->estado,
                    'telefone'       => $c->telefone,
                    'origem'         => $c->origem,
                    'latitude'       => (float) $lat,
                    'longitude'      => (float) $lng,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $candidatos]);
    }

    /* ─── Helpers ────────────────────────────────────────────────────── */

    private function baseQuery(int $empresaId)
    {
        return Envio::with(['candidato.user:id,name,email', 'candidato.franquia:id,nome', 'vaga:id,titulo,salario_min,salario_max,canal', 'kanbanEtapa:id,nome', 'pareceres'])
            ->whereHas('vaga', fn($q) => $q->where('empresa_id', $empresaId));
    }

    private function payload(Envio $e): array
    {
        $parecer = $e->relationLoaded('pareceres') ? $e->pareceres->sortByDesc('created_at')->first() : null;

        return [
            'id'                => $e->id,
            'curriculo_id'      => $e->curriculo_id,
            'vaga_id'           => $e->vaga_id,
            'kanban_etapa_id'   => $e->kanban_etapa_id,
            'kanban_etapa_nome' => $e->kanbanEtapa?->nome ?? 'Recebido',
            'origem'            => $e->origem,
            'franquia_nome'     => $e->candidato?->franquia?->nome,
            'status'            => $e->status_empresa,
            'parecer_id'        => $parecer?->id,
            'parecer_texto'     => $parecer?->texto,
            'parecer_autor'     => $parecer?->autor,
            'observacao'        => $e->observacao,
            'salario_aprovado'  => $e->salario_aprovado,
            'tipo_contrato'     => $e->tipo_contrato,
            'data_admissao'     => $e->data_admissao?->toDateString(),
            'data_saida'        => $e->data_saida?->toDateString(),
            // salário da vaga: pré-preenche o campo ao aprovar (igual à franquia)
            'vaga_salario'      => $e->vaga?->salario_max ?? $e->vaga?->salario_min,
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
                'canal'       => $e->vaga->canal,
            ] : null,
            // Vaga conduzida pela agência: a empresa não emite parecer
            'parecer_bloqueado' => $e->vaga?->canal === 'agencia',
        ];
    }
}
