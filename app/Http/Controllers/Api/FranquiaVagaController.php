<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Envio;
use App\Models\Franquia;
use App\Models\Vaga;
use App\Models\VagaDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FranquiaVagaController extends Controller
{
    use HasTokenContext;

    // GET /franquia/vagas
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $query = Vaga::with(['empresa:id,razao_social,nome_fantasia', 'franquiasCompartilhadas'])
            ->where(function ($q) use ($franquiaId) {
                $q->where('franquia_id', $franquiaId)
                  ->orWhereHas('franquiasCompartilhadas', function ($sub) use ($franquiaId) {
                      $sub->where('franquias.id', $franquiaId);
                  });
            })
            ->withCount('envios as total_candidatos');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('cidade')) {
            $query->where('cidade', 'like', '%' . $request->cidade . '%');
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('bairro')) {
            $query->where('bairro', 'like', '%' . $request->bairro . '%');
        }
        if ($request->filled('titulo')) {
            $query->where('titulo', 'like', '%' . $request->titulo . '%');
        }

        $vagas = $query->orderByDesc('created_at')->paginate(20);

        $items = $vagas->getCollection()->map(fn($v) => [
            'id'                => $v->id,
            'titulo'            => $v->titulo,
            'empresa'           => ['id' => $v->empresa_id, 'razao_social' => $v->empresa?->razao_social],
            'cidade'            => $v->cidade,
            'estado'            => $v->estado,
            'bairro'            => $v->bairro,
            'modalidade'        => $v->regime_trabalho,
            'tipo_contrato'     => $v->tipo_contrato,
            'salario_min'       => $v->salario_min,
            'salario_max'       => $v->salario_max,
            'salario_oculto'    => !$v->exibir_salario,
            'vagas_disponiveis' => $v->quantidade_vagas,
            'total_candidatos'  => $v->total_candidatos,
            'ativa'             => $v->status === 'publicada',
            'status'            => $v->status,
            'expira_em'         => $v->data_fechamento,
            'created_at'        => $v->created_at,
            'is_shared'         => $v->franquia_id !== $franquiaId,
            'shared_with'       => $v->franquiasCompartilhadas->map(fn($f) => ['id' => $f->id, 'nome' => $f->nome]),
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $vagas->total(),
                'per_page'     => $vagas->perPage(),
                'current_page' => $vagas->currentPage(),
                'last_page'    => $vagas->lastPage(),
            ],
        ]);
    }

    // POST /franquia/vagas
    public function store(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $validated = $request->validate([
            'empresa_id'       => 'required|integer',
            'titulo'           => 'required|string|max:255',
            'descricao'        => 'nullable|string',
            'requisitos'       => 'nullable|string',
            'cidade'           => 'nullable|string|max:100',
            'estado'           => 'nullable|string|size:2',
            'bairro'           => 'nullable|string|max:100',
            'cep'              => 'nullable|string|max:10',
            'modalidade'       => 'nullable|in:presencial,remoto,hibrido',
            'tipo_contrato'    => 'nullable|in:clt,pj,temporario,estagio,autonomo',
            'salario_min'      => 'nullable|numeric|min:0',
            'salario_max'      => 'nullable|numeric|min:0',
            'salario_oculto'   => 'boolean',
            'vagas_disponiveis'=> 'nullable|integer|min:1',
            'expira_em'        => 'nullable|date',
            'nivel_vaga_id'    => 'nullable|integer|exists:niveis_vagas,id',
            'taxa_servico'     => 'nullable|numeric|min:0|max:100',
        ]);

        // Valida que a empresa pertence a esta franquia
        $empresa = Empresa::where('id', $validated['empresa_id'])
            ->where('franquia_id', $franquiaId)
            ->firstOrFail();

        $vaga = Vaga::create([
            'codigo'          => $this->gerarCodigo(),
            'franquia_id'     => $franquiaId,
            'empresa_id'      => $empresa->id,
            'titulo'          => $validated['titulo'],
            'descricao'       => $validated['descricao'] ?? null,
            'requisitos'      => $validated['requisitos'] ?? null,
            'cidade'          => $validated['cidade'] ?? null,
            'estado'          => $validated['estado'] ?? null,
            'bairro'          => $validated['bairro'] ?? null,
            'cep'             => $validated['cep'] ?? null,
            'regime_trabalho' => $validated['modalidade'] ?? null,
            'tipo_contrato'   => $validated['tipo_contrato'] ?? null,
            'salario_min'     => $validated['salario_min'] ?? null,
            'salario_max'     => $validated['salario_max'] ?? null,
            'exibir_salario'  => !($validated['salario_oculto'] ?? false),
            'quantidade_vagas'=> $validated['vagas_disponiveis'] ?? 1,
            'data_fechamento' => $validated['expira_em'] ?? null,
            'nivel_vaga_id'   => $validated['nivel_vaga_id'] ?? null,
            'taxa_servico'    => $validated['taxa_servico'] ?? null,
            'status'          => 'publicada',
            'data_abertura'   => now(),
        ]);

        return response()->json([
            'message' => 'Vaga criada com sucesso.',
            'vaga'    => $vaga,
        ], 201);
    }

    private function gerarCodigo(): string
    {
        $ultimo = Vaga::withTrashed()
            ->where('codigo', 'like', 'VG-%')
            ->orderByDesc('id')
            ->value('codigo');

        $numero = $ultimo ? (int) substr($ultimo, 3) + 1 : 1;
        return 'VG-' . str_pad($numero, 4, '0', STR_PAD_LEFT);
    }

    // GET /franquia/vagas/niveis
    public function niveis(Request $request)
    {
        $niveis = \App\Models\NivelVaga::orderBy('ordem')->orderBy('nome')->get(['id', 'nome', 'ordem']);
        return response()->json(['data' => $niveis]);
    }

    // GET /franquia/vagas/{id}
    public function show(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $vaga = Vaga::with(['empresa:id,razao_social,nome_fantasia,cidade', 'documentos'])
            ->where(function ($q) use ($franquiaId) {
                $q->where('franquia_id', $franquiaId)
                  ->orWhereHas('franquiasCompartilhadas', function ($sub) use ($franquiaId) {
                      $sub->where('franquias.id', $franquiaId);
                  });
            })
            ->withCount('envios as total_candidatos')
            ->findOrFail($id);

        return response()->json(['data' => [
            'id'                => $vaga->id,
            'titulo'            => $vaga->titulo,
            'descricao'         => $vaga->descricao,
            'requisitos'        => $vaga->requisitos,
            'empresa'           => $vaga->empresa,
            'cidade'            => $vaga->cidade,
            'estado'            => $vaga->estado,
            'bairro'            => $vaga->bairro,
            'cep'               => $vaga->cep,
            'nivel_vaga_id'     => $vaga->nivel_vaga_id,
            'taxa_servico'      => $vaga->taxa_servico,
            'modalidade'        => $vaga->regime_trabalho,
            'tipo_contrato'     => $vaga->tipo_contrato,
            'salario_min'       => $vaga->salario_min,
            'salario_max'       => $vaga->salario_max,
            'salario_oculto'    => !$vaga->exibir_salario,
            'vagas_disponiveis' => $vaga->quantidade_vagas,
            'total_candidatos'  => $vaga->total_candidatos,
            'ativa'             => $vaga->status === 'publicada',
            'status'            => $vaga->status,
            'expira_em'         => $vaga->data_fechamento,
            'created_at'        => $vaga->created_at,
            'is_shared'         => $vaga->franquia_id !== $franquiaId,
            'documentos'        => $vaga->documentos,
        ]]);
    }

    // PUT /franquia/vagas/{id}
    public function update(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $vaga = Vaga::where('franquia_id', $franquiaId)->findOrFail($id);

        $validated = $request->validate([
            'titulo'            => 'sometimes|required|string|max:255',
            'status'            => 'nullable|in:publicada,rascunho,pausada,cancelada,fechada',
            'descricao'         => 'nullable|string',
            'requisitos'        => 'nullable|string',
            'beneficios'        => 'nullable|string',
            'nivel_vaga_id'     => 'nullable|integer|exists:niveis_vagas,id',
            'cidade'            => 'nullable|string|max:100',
            'estado'            => 'nullable|string|size:2',
            'bairro'            => 'nullable|string|max:100',
            'cep'               => 'nullable|string|max:10',
            'modalidade'        => 'nullable|in:presencial,remoto,hibrido',
            'tipo_contrato'     => 'nullable|in:clt,pj,temporario,estagio,autonomo',
            'salario_min'       => 'nullable|numeric|min:0',
            'salario_max'       => 'nullable|numeric|min:0',
            'salario_oculto'    => 'boolean',
            'vagas_disponiveis' => 'nullable|integer|min:1',
            'expira_em'         => 'nullable|date',
            'taxa_servico'      => 'nullable|numeric|min:0|max:100',
        ]);

        $data = [];
        foreach ($validated as $key => $val) {
            match ($key) {
                'modalidade'        => $data['regime_trabalho']  = $val,
                'salario_oculto'    => $data['exibir_salario']   = !$val,
                'vagas_disponiveis' => $data['quantidade_vagas'] = $val,
                'expira_em'         => $data['data_fechamento']  = $val,
                default             => $data[$key]               = $val,
            };
        }

        $vaga->update($data);

        return response()->json(['message' => 'Vaga updated successfully.', 'data' => $vaga->fresh()]);
    }

    // DELETE /franquia/vagas/{id}
    public function destroy(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vaga = Vaga::where('franquia_id', $franquiaId)->findOrFail($id);
        $vaga->delete();

        return response()->json(['message' => 'Vaga removida.']);
    }

    // PATCH /franquia/vagas/{id}/toggle-ativa
    public function toggleAtiva(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vaga = Vaga::where('franquia_id', $franquiaId)->findOrFail($id);

        $novoStatus = $vaga->status === 'publicada' ? 'rascunho' : 'publicada';
        $vaga->update(['status' => $novoStatus]);

        return response()->json(['message' => 'Vaga atualizada.', 'ativa' => $novoStatus === 'publicada']);
    }

    // POST /franquia/vagas/{vagaId}/vincular
    public function vincular(Request $request, int $vagaId)
    {
        $franquiaId = $this->tokenContextId($request);

        $vaga = Vaga::where(function ($q) use ($franquiaId) {
            $q->where('franquia_id', $franquiaId)
              ->orWhereHas('franquiasCompartilhadas', function ($sub) use ($franquiaId) {
                  $sub->where('franquias.id', $franquiaId);
              });
        })->findOrFail($vagaId);

        $request->validate(['candidato_id' => 'required|integer|exists:candidatos,id']);

        $candidato = Candidato::findOrFail($request->candidato_id);

        // Usa o currículo ativo do candidato
        $curriculo = $candidato->documentos()->where('ativo', true)->first()
            ?? $candidato->documentos()->latest()->first();

        if (!$curriculo) {
            return response()->json(['message' => 'Candidato não possui currículo cadastrado.'], 422);
        }

        $envio = Envio::firstOrCreate(
            ['candidato_id' => $candidato->id, 'vaga_id' => $vaga->id],
            ['curriculo_id' => $curriculo->id, 'status' => 'enviado']
        );

        return response()->json([
            'message' => 'Candidato vinculado com sucesso.',
            'data'    => ['candidato_id' => $candidato->id, 'vaga_id' => $vaga->id, 'status' => $envio->status],
        ], 201);
    }

    // GET /franquia/vagas/{vagaId}/candidatos
    public function candidatos(Request $request, int $vagaId)
    {
        $franquiaId = $this->tokenContextId($request);
        $vaga = Vaga::where(function ($q) use ($franquiaId) {
            $q->where('franquia_id', $franquiaId)
              ->orWhereHas('franquiasCompartilhadas', function ($sub) use ($franquiaId) {
                  $sub->where('franquias.id', $franquiaId);
              });
        })->findOrFail($vagaId);

        $envios = Envio::with('candidato.user:id,name')
            ->where('vaga_id', $vaga->id)
            ->orderByDesc('created_at')
            ->get();

        $data = $envios->map(fn($e) => [
            'id'           => $e->id,
            'candidato_id' => $e->candidato_id,
            'nome'         => $e->candidato?->user?->name,
            'franquia'     => null,
            'status'       => $e->status,
            'vaga_id'      => $vaga->id,
            'vinculado_em' => $e->created_at,
        ]);

        return response()->json(['data' => $data]);
    }

    // POST /franquia/vagas/{id}/documentos
    public function storeDocumento(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vaga = Vaga::where('franquia_id', $franquiaId)->findOrFail($id);

        $request->validate([
            'documento' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($request->hasFile('documento')) {
            $file = $request->file('documento');
            $path = $file->store('vagas/documentos', 'public');

            $doc = VagaDocumento::create([
                'vaga_id'      => $vaga->id,
                'arquivo_path' => Storage::disk('public')->url($path),
                'arquivo_nome' => $file->getClientOriginalName(),
                'tamanho_kb'   => round($file->getSize() / 1024),
            ]);

            return response()->json(['message' => 'Documento adicionado.', 'data' => $doc], 201);
        }

        return response()->json(['message' => 'Arquivo inválido.'], 422);
    }

    // DELETE /franquia/vagas/{vagaId}/documentos/{docId}
    public function destroyDocumento(Request $request, int $vagaId, int $docId)
    {
        $franquiaId = $this->tokenContextId($request);
        $vaga = Vaga::where('franquia_id', $franquiaId)->findOrFail($vagaId);
        $doc = VagaDocumento::where('vaga_id', $vaga->id)->findOrFail($docId);

        $oldPath = str_replace(Storage::disk('public')->url(''), '', $doc->arquivo_path);
        Storage::disk('public')->delete($oldPath);

        $doc->delete();

        return response()->json(['message' => 'Documento removido.']);
    }

    // GET /franquia/vagas/{id}/compartilhar
    public function listCompartilhadas(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vaga = Vaga::where('franquia_id', $franquiaId)->findOrFail($id);

        $sharedIds = $vaga->franquiasCompartilhadas()->pluck('franquias.id')->toArray();
        $franquias = Franquia::where('active', true)
            ->where('id', '!=', $franquiaId)
            ->get(['id', 'nome', 'tipo'])
            ->map(fn($f) => [
                'id'        => $f->id,
                'nome'      => $f->nome,
                'tipo'      => $f->tipo,
                'is_shared' => in_array($f->id, $sharedIds),
            ]);

        return response()->json(['data' => $franquias]);
    }

    // POST /franquia/vagas/{id}/compartilhar
    public function compartilhar(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vaga = Vaga::where('franquia_id', $franquiaId)->findOrFail($id);

        $request->validate([
            'franquia_ids'   => 'required|array',
            'franquia_ids.*' => 'integer|exists:franquias,id',
        ]);

        $vaga->franquiasCompartilhadas()->sync($request->franquia_ids);

        return response()->json(['message' => 'Vaga compartilhada com sucesso.']);
    }
}
