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

    /** Aborta com 403 se a franquia nao for Premium. */
    private function assertPremium(int $franquiaId, string $mensagem): void
    {
        $franquia = Franquia::findOrFail($franquiaId);
        if ($franquia->tipo !== 'premium') {
            abort(response()->json(['message' => $mensagem], 403));
        }
    }

    /**
     * Executa uma query de Vaga já filtrada por acesso (dono, ou dono+compartilhada
     * conforme o caso) e retorna o registro. Se não encontrar nada, diferencia:
     * - a vaga não existe de fato               -> 404 "Vaga não encontrada."
     * - a vaga existe mas não pertence a você    -> 403 (mensagem explicativa)
     * Sem isso, os dois casos ficavam indistinguíveis pro usuário (ambos apareciam
     * como "Recurso não encontrado.", sem explicar que a vaga é de outra franquia).
     */
    private function vagaOuAbortar(\Illuminate\Database\Eloquent\Builder $query, int $id): Vaga
    {
        $vaga = $query->find($id);
        if ($vaga) {
            return $vaga;
        }
        if (!Vaga::whereKey($id)->exists()) {
            abort(response()->json(['message' => 'Vaga não encontrada.'], 404));
        }
        abort(response()->json([
            'message' => 'Esta vaga pertence a outra franquia e não está disponível para você.',
        ], 403));
    }

    // GET /franquia/vagas
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        // Nova regra: todos os perfis visualizam todas as vagas cadastradas.
        $query = Vaga::with(['empresa:id,razao_social,nome_fantasia', 'franquiasCompartilhadas', 'franquia:id,nome,tipo,telefone,email,email_franqueado'])
            ->withCount('envios as total_candidatos');

        if ($request->filled('status')) {
            if ($request->status === 'ativa') {
                $query->where('status', 'publicada');
            } elseif ($request->status === 'inativa') {
                $query->where('status', '!=', 'publicada');
            } else {
                $query->where('status', $request->status);
            }
        }
        if ($request->filled('convite')) {
            if ($request->convite === 'sim') {
                $query->whereHas('franquiasCompartilhadas', fn($q) => $q->where('franquias.id', $franquiaId));
            } elseif ($request->convite === 'nao') {
                $query->whereDoesntHave('franquiasCompartilhadas', fn($q) => $q->where('franquias.id', $franquiaId));
            }
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
        if ($request->filled('tipo_contrato')) {
            $query->where('tipo_contrato', $request->tipo_contrato);
        }
        if ($request->filled('modalidade')) {
            $query->where('regime_trabalho', $request->modalidade);
        }
        if ($request->filled('empresa_id')) {
            $query->where('empresa_id', $request->empresa_id);
        }
        if ($request->filled('genero')) {
            $query->where('genero', $request->genero);
        }
        if ($request->filled('turno')) {
            $query->where('turno', $request->turno);
        }

        // Ordenação: padrão pela última atualização (mais recente primeiro)
        $sort = in_array($request->get('sort'), ['created_at', 'updated_at', 'titulo']) ? $request->get('sort') : 'updated_at';
        $dir  = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $vagas = $query->orderBy($sort, $dir)->paginate(20);

        $items = $vagas->getCollection()->map(fn($v) => [
            'id'                => $v->id,
            'titulo'            => $v->titulo,
            'empresa'           => ['id' => $v->empresa_id, 'razao_social' => $v->empresa?->razao_social ?? $v->empresa?->nome_fantasia ?? 'Empresa não informada'],
            'cidade'            => $v->cidade,
            'estado'            => $v->estado,
            'bairro'            => $v->bairro,
            'modalidade'        => $v->regime_trabalho,
            'tipo_contrato'     => $v->tipo_contrato,
            'salario_min'       => $v->salario_min,
            'salario_max'       => $v->salario_max,
            'salario_oculto'    => !$v->exibir_salario,
            'horario_trabalho'  => $v->horario_trabalho,
            'turno'             => $v->turno,
            'carga_horaria'     => $v->carga_horaria,
            'vagas_disponiveis' => $v->quantidade_vagas,
            'total_candidatos'  => $v->total_candidatos,
            'ativa'             => $v->status === 'publicada',
            'status'            => $v->status,
            'expira_em'         => $v->data_fechamento,
            'created_at'        => $v->created_at,
            'updated_at'        => $v->updated_at,
            'taxa_servico'      => $v->taxa_servico,
            'genero'            => $v->genero,
            'turno'             => $v->turno,
            'is_owner'          => $v->franquia_id === $franquiaId,
            'is_invited'        => $v->franquiasCompartilhadas->contains('id', $franquiaId),
            'shared_with'       => $v->franquiasCompartilhadas->map(fn($f) => ['id' => $f->id, 'nome' => $f->nome]),
            'franquia_dona'     => $v->franquia ? [
                'id'       => $v->franquia->id,
                'nome'     => $v->franquia->nome,
                'tipo'     => $v->franquia->tipo,
                'telefone' => $v->franquia->telefone,
                'email'    => $v->franquia->email_franqueado ?? $v->franquia->email,
            ] : null,
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
        $this->assertPremium($franquiaId, 'Apenas franquias Premium podem criar vagas.');

        $validated = $request->validate([
            'empresa_id'       => 'required|integer',
            'titulo'           => 'required|string|max:255',
            'descricao'        => 'nullable|string',
            'requisitos'       => 'nullable|string',
            'cidade'           => 'nullable|string|max:100',
            'estado'           => 'nullable|string|size:2',
            'bairro'           => 'nullable|string|max:100',
            'cep'              => 'nullable|string|max:10',
            'logradouro'       => 'nullable|string|max:255',
            'numero'           => 'nullable|string|max:20',
            'modalidade'       => 'nullable|in:presencial,remoto,hibrido',
            'tipo_contrato'    => 'nullable|string|max:50',
            'salario_min'      => 'nullable|numeric|min:0',
            'salario_max'      => 'nullable|numeric|min:0',
            'salario_oculto'   => 'boolean',
            'vagas_disponiveis'=> 'nullable|integer|min:1',
            'expira_em'        => 'nullable|date',
            'nivel_vaga_id'    => 'nullable|integer|exists:niveis_vagas,id',
            'taxa_servico'     => 'nullable|numeric|min:0|max:100',
            'requer_validacao_premium' => 'nullable|boolean',
            'genero'            => 'nullable|string|max:20',
            'turno'             => 'nullable|string|max:20',
            'horario_trabalho'  => 'nullable|string|max:50',
            'nome_requisitante' => 'nullable|string|max:255',
            'email_requisitante'=> 'nullable|email|max:255',
            'beneficio_ids'      => 'nullable|array',
            'beneficio_ids.*'    => 'integer|exists:beneficios_catalogo,id',
            'franquia_ids'       => 'nullable|array',
            'franquia_ids.*'     => 'integer|exists:franquias,id',
            'observacoes'        => 'nullable|string',
            'requisitantes'          => 'nullable|array',
            'requisitantes.*.nome'   => 'required_with:requisitantes|string|max:255',
            'requisitantes.*.email'  => 'nullable|email|max:255',
        ]);

        // Compatibilidade: primeiro requisitante espelhado nos campos antigos
        if (!empty($validated['requisitantes'])) {
            $validated['nome_requisitante']  = $validated['requisitantes'][0]['nome'] ?? null;
            $validated['email_requisitante'] = $validated['requisitantes'][0]['email'] ?? null;
        }

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
            'logradouro'      => $validated['logradouro'] ?? null,
            'numero'          => $validated['numero'] ?? null,
            // regime_trabalho/tipo_contrato não são mais escolhidos no formulário;
            // colunas são NOT NULL no banco, então usamos os mesmos defaults do schema.
            'regime_trabalho' => $validated['modalidade'] ?? 'presencial',
            'tipo_contrato'   => $validated['tipo_contrato'] ?? 'clt',
            'salario_min'     => $validated['salario_min'] ?? null,
            'salario_max'     => $validated['salario_max'] ?? null,
            'exibir_salario'  => !($validated['salario_oculto'] ?? false),
            'quantidade_vagas'=> $validated['vagas_disponiveis'] ?? 1,
            'data_fechamento' => $validated['expira_em'] ?? null,
            'nivel_vaga_id'   => $validated['nivel_vaga_id'] ?? null,
            'taxa_servico'    => $validated['taxa_servico'] ?? null,
            'requer_validacao_premium' => $request->boolean('requer_validacao_premium'),
            'genero'             => $validated['genero'] ?? null,
            'turno'              => $validated['turno'] ?? null,
            'horario_trabalho'   => $validated['horario_trabalho'] ?? null,
            'nome_requisitante'  => $validated['nome_requisitante'] ?? null,
            'email_requisitante' => $validated['email_requisitante'] ?? null,
            'requisitantes'      => $validated['requisitantes'] ?? null,
            'status'          => 'publicada',
            'data_abertura'   => now(),
        ]);

        if (!empty($validated['beneficio_ids'])) {
            $vaga->beneficiosCatalogo()->sync($validated['beneficio_ids']);
        }

        if (!empty($validated['franquia_ids'])) {
            $vaga->franquiasCompartilhadas()->sync($validated['franquia_ids']);
        }

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

    // GET /franquia/vagas/franquias
    public function franquiasDisponiveis(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $franquias = Franquia::where('active', true)
            ->where('id', '!=', $franquiaId)
            ->orderBy('nome')
            ->get(['id', 'codigo', 'nome', 'tipo', 'cidade', 'estado', 'cidade_empresa', 'estado_empresa'])
            ->map(fn($f) => [
                'id'     => $f->id,
                'codigo' => $f->codigo,
                'nome'   => $f->nome,
                'tipo'   => $f->tipo,
                'cidade' => $f->cidade ?? $f->cidade_empresa,
                'estado' => $f->estado ?? $f->estado_empresa,
            ]);

        return response()->json(['data' => $franquias]);
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

        // Visualização liberada para todas as franquias (mesmo não convidadas);
        // ações de vincular/editar continuam restritas nos respectivos endpoints.
        $vaga = $this->vagaOuAbortar(
            Vaga::with(['empresa:id,razao_social,nome_fantasia,cidade', 'documentos', 'beneficiosCatalogo', 'franquia:id,nome,tipo,telefone,email,email_franqueado'])
                ->withCount('envios as total_candidatos'),
            $id
        );

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
            'logradouro'        => $vaga->logradouro,
            'numero'            => $vaga->numero,
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
            'is_owner'          => $vaga->franquia_id === $franquiaId,
            'updated_at'        => $vaga->updated_at,
            'carga_horaria'     => $vaga->carga_horaria,
            'observacoes'       => $vaga->observacoes,
            'beneficios'        => $vaga->beneficios,
            'codigo'            => $vaga->codigo,
            'requisitantes'     => $vaga->requisitantes,
            'franquia_dona'     => $vaga->franquia ? [
                'id'       => $vaga->franquia->id,
                'nome'     => $vaga->franquia->nome,
                'tipo'     => $vaga->franquia->tipo,
                'telefone' => $vaga->franquia->telefone,
                'email'    => $vaga->franquia->email_franqueado ?? $vaga->franquia->email,
            ] : null,
            'documentos'        => $vaga->documentos,
            'genero'             => $vaga->genero,
            'turno'              => $vaga->turno,
            'horario_trabalho'   => $vaga->horario_trabalho,
            'nome_requisitante'  => $vaga->nome_requisitante,
            'email_requisitante' => $vaga->email_requisitante,
            'beneficio_ids'      => $vaga->beneficiosCatalogo->pluck('id'),
            'beneficios_selecionados' => $vaga->beneficiosCatalogo,
        ]]);
    }

    // PUT /franquia/vagas/{id}
    public function update(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $vaga = $this->vagaOuAbortar(Vaga::where('franquia_id', $franquiaId), $id);

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
            'logradouro'        => 'nullable|string|max:255',
            'numero'            => 'nullable|string|max:20',
            'modalidade'        => 'nullable|in:presencial,remoto,hibrido',
            'tipo_contrato'     => 'nullable|string|max:50',
            'salario_min'       => 'nullable|numeric|min:0',
            'salario_max'       => 'nullable|numeric|min:0',
            'salario_oculto'    => 'boolean',
            'vagas_disponiveis' => 'nullable|integer|min:1',
            'expira_em'         => 'nullable|date',
            'taxa_servico'      => 'nullable|numeric|min:0|max:100',
            'requer_validacao_premium' => 'nullable|boolean',
            'genero'            => 'nullable|string|max:20',
            'turno'             => 'nullable|string|max:20',
            'horario_trabalho'  => 'nullable|string|max:50',
            'nome_requisitante' => 'nullable|string|max:255',
            'email_requisitante'=> 'nullable|email|max:255',
            'beneficio_ids'      => 'nullable|array',
            'beneficio_ids.*'    => 'integer|exists:beneficios_catalogo,id',
            'observacoes'        => 'nullable|string',
            'requisitantes'          => 'nullable|array',
            'requisitantes.*.nome'   => 'required_with:requisitantes|string|max:255',
            'requisitantes.*.email'  => 'nullable|email|max:255',
        ]);

        // Compatibilidade: primeiro requisitante espelhado nos campos antigos
        if (array_key_exists('requisitantes', $validated) && !empty($validated['requisitantes'])) {
            $validated['nome_requisitante']  = $validated['requisitantes'][0]['nome'] ?? null;
            $validated['email_requisitante'] = $validated['requisitantes'][0]['email'] ?? null;
        }

        $beneficioIds = $validated['beneficio_ids'] ?? null;
        unset($validated['beneficio_ids']);

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

        if ($beneficioIds !== null) {
            $vaga->beneficiosCatalogo()->sync($beneficioIds);
        }

        return response()->json(['message' => 'Vaga updated successfully.', 'data' => $vaga->fresh()]);
    }

    // DELETE /franquia/vagas/{id}
    public function destroy(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vaga = $this->vagaOuAbortar(Vaga::where('franquia_id', $franquiaId), $id);
        $vaga->delete();

        return response()->json(['message' => 'Vaga removida.']);
    }

    // PATCH /franquia/vagas/{id}/toggle-ativa
    public function toggleAtiva(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $this->assertPremium($franquiaId, 'Apenas franquias Premium podem alterar o status da vaga.');
        $vaga = $this->vagaOuAbortar(Vaga::where('franquia_id', $franquiaId), $id);

        $novoStatus = $vaga->status === 'publicada' ? 'rascunho' : 'publicada';
        $vaga->update(['status' => $novoStatus]);

        return response()->json(['message' => 'Vaga atualizada.', 'ativa' => $novoStatus === 'publicada']);
    }

    // POST /franquia/vagas/{vagaId}/vincular
    public function vincular(Request $request, int $vagaId)
    {
        $franquiaId = $this->tokenContextId($request);

        $vaga = $this->vagaOuAbortar(Vaga::where(function ($q) use ($franquiaId) {
            $q->where('franquia_id', $franquiaId)
              ->orWhereHas('franquiasCompartilhadas', function ($sub) use ($franquiaId) {
                  $sub->where('franquias.id', $franquiaId);
              });
        }), $vagaId);

        $request->validate(['candidato_id' => 'required|integer|exists:candidatos,id']);

        $candidato = Candidato::findOrFail($request->candidato_id);

        if (!$candidato->pareceres()->exists()) {
            return response()->json([
                'message' => 'Candidato precisa ter um parecer registrado antes de ser vinculado a uma vaga.',
            ], 422);
        }

        // Usa o currículo ativo do candidato
        $curriculo = $candidato->documentos()->where('ativo', true)->first()
            ?? $candidato->documentos()->latest()->first();

        // Permite vincular candidatos do banco que ainda nao tem curriculo anexado.
        $envio = Envio::firstOrCreate(
            ['candidato_id' => $candidato->id, 'vaga_id' => $vaga->id],
            ['curriculo_id' => $curriculo?->id, 'status' => 'enviado']
        );

        return response()->json([
            'message' => 'Candidato vinculado com sucesso.',
            'data'    => ['candidato_id' => $candidato->id, 'vaga_id' => $vaga->id, 'status' => $envio->status],
        ], 201);
    }

    // GET /franquia/vagas/{vagaId}/candidatos
    public function candidatos(Request $request, int $vagaId)
    {
        // Visualização dos candidatos vinculados liberada para todas as franquias
        $vaga = $this->vagaOuAbortar(Vaga::query(), $vagaId);

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
        $vaga = $this->vagaOuAbortar(Vaga::where('franquia_id', $franquiaId), $id);

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
        $vaga = $this->vagaOuAbortar(Vaga::where('franquia_id', $franquiaId), $vagaId);
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
        $vaga = $this->vagaOuAbortar(Vaga::where('franquia_id', $franquiaId), $id);

        $sharedIds = $vaga->franquiasCompartilhadas()->pluck('franquias.id')->toArray();
        $franquias = Franquia::where('active', true)
            ->where('id', '!=', $franquiaId)
            ->get(['id', 'nome', 'tipo', 'cidade', 'estado', 'cidade_empresa', 'estado_empresa'])
            ->map(fn($f) => [
                'id'        => $f->id,
                'nome'      => $f->nome,
                'tipo'      => $f->tipo,
                'cidade'    => $f->cidade ?? $f->cidade_empresa,
                'estado'    => $f->estado ?? $f->estado_empresa,
                'is_shared' => in_array($f->id, $sharedIds),
            ]);

        return response()->json(['data' => $franquias]);
    }

    // POST /franquia/vagas/{id}/compartilhar
    public function compartilhar(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $this->assertPremium($franquiaId, 'Apenas franquias Premium podem convidar franquias.');
        $vaga = $this->vagaOuAbortar(Vaga::where('franquia_id', $franquiaId), $id);

        $request->validate([
            'franquia_ids'   => 'required|array',
            'franquia_ids.*' => 'integer|exists:franquias,id',
        ]);

        $vaga->franquiasCompartilhadas()->sync($request->franquia_ids);

        return response()->json(['message' => 'Vaga compartilhada com sucesso.']);
    }
}
