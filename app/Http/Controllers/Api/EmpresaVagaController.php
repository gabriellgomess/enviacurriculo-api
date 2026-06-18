<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Vaga;
use Illuminate\Http\Request;

class EmpresaVagaController extends Controller
{
    use HasTokenContext;

    /** status do contrato → status no banco */
    private const STATUS_IN = [
        'aberta'       => 'publicada',
        'em_andamento' => 'em_andamento',
        'fechada'      => 'fechada',
        'cancelada'    => 'cancelada',
    ];

    /** status no banco → status do contrato */
    private const STATUS_OUT = [
        'rascunho'     => 'aberta',
        'publicada'    => 'aberta',
        'pausada'      => 'em_andamento',
        'em_andamento' => 'em_andamento',
        'fechada'      => 'fechada',
        'cancelada'    => 'cancelada',
    ];

    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $query = Vaga::where('empresa_id', $empresaId)->withCount('envios');

        if ($request->filled('status') && isset(self::STATUS_IN[$request->status])) {
            $dbStatus = self::STATUS_IN[$request->status];
            $query->when($dbStatus === 'publicada',
                fn($q) => $q->whereIn('status', ['rascunho', 'publicada']),
                fn($q) => $q->where('status', $dbStatus));
        }

        if ($request->filled('canal')) {
            $query->where('canal', $request->canal);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('titulo', 'like', "%{$s}%")->orWhere('cidade', 'like', "%{$s}%"));
        }

        $vagas = $query->orderByDesc('created_at')->paginate(20);

        $counts = Vaga::where('empresa_id', $empresaId)
            ->selectRaw("SUM(status IN ('rascunho','publicada')) as abertas,
                         SUM(status IN ('pausada','em_andamento')) as em_andamento,
                         SUM(status = 'fechada') as fechadas,
                         SUM(status = 'cancelada') as canceladas")
            ->first();

        return response()->json([
            'data' => collect($vagas->items())->map(fn($v) => $this->payload($v)),
            'meta' => [
                'current_page' => $vagas->currentPage(), 'last_page' => $vagas->lastPage(),
                'per_page' => $vagas->perPage(), 'total' => $vagas->total(),
                'abertas'      => (int) $counts->abertas,
                'em_andamento' => (int) $counts->em_andamento,
                'fechadas'     => (int) $counts->fechadas,
                'canceladas'   => (int) $counts->canceladas,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        if ($gate = $this->gatePlano($empresaId)) {
            return $gate;
        }

        $data = $this->validateVaga($request);

        $vaga = Vaga::create([
            ...$this->mapToDb($data),
            'empresa_id'    => $empresaId,
            'codigo'        => $this->gerarCodigo(),
            'status'        => 'publicada',
            'data_abertura' => now(),
        ]);

        if (array_key_exists('beneficios', $data)) {
            $vaga->beneficiosCatalogo()->sync($data['beneficios'] ?? []);
        }

        return response()->json(['data' => [
            'id'     => $vaga->id,
            'titulo' => $vaga->titulo,
            'status' => self::STATUS_OUT[$vaga->status],
            'canal'  => $vaga->canal,
        ]], 201);
    }

    public function show(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $vaga = Vaga::with('beneficiosCatalogo:id,nome')->withCount('envios')
            ->where('empresa_id', $empresaId)->findOrFail($id);

        return response()->json(['data' => $this->payload($vaga, full: true)]);
    }

    public function update(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        if ($gate = $this->gatePlano($empresaId)) {
            return $gate;
        }

        $vaga = Vaga::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $this->validateVaga($request);
        $vaga->update($this->mapToDb($data));

        if (array_key_exists('beneficios', $data)) {
            $vaga->beneficiosCatalogo()->sync($data['beneficios'] ?? []);
        }

        return response()->json([
            'message' => 'Vaga atualizada com sucesso.',
            'vaga'    => $this->payload($vaga->fresh(['beneficiosCatalogo'])->loadCount('envios'), full: true),
        ]);
    }

    // changeStatus removido: a empresa nao altera mais o status operacional da
    // vaga. Apenas Admin e Franquia Premium podem (regra revisada com o cliente).

    public function destroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $vaga = Vaga::withCount('envios')->where('empresa_id', $empresaId)->findOrFail($id);

        if ($vaga->envios_count > 0 && !in_array($vaga->status, ['fechada', 'cancelada'])) {
            return response()->json([
                'message' => 'A vaga possui candidatos vinculados. Feche ou cancele a vaga antes de removê-la.',
            ], 422);
        }

        $vaga->delete(); // soft delete

        return response()->noContent();
    }

    /* ─── Helpers ────────────────────────────────────────────────────── */

    private function validateVaga(Request $request): array
    {
        return $request->validate([
            'titulo'           => 'required|string|max:255',
            'descricao'        => 'nullable|string',
            'requisitos'       => 'nullable|string',
            'beneficios_texto' => 'nullable|string',
            'beneficios'       => 'nullable|array',
            'beneficios.*'     => 'integer|exists:beneficios_catalogo,id',
            'canal'            => 'required|in:agencia,plataforma,ambos',
            'modalidade'       => 'required|in:presencial,remoto,hibrido',
            'tipo_contrato'    => 'required|in:clt,pj,estagio,temporario,freelancer,outros',
            'nivel_vaga_id'    => 'nullable|integer|exists:niveis_vagas,id',
            'numero_posicoes'  => 'nullable|integer|min:1',
            'salario_min'      => 'nullable|numeric|min:0',
            'salario_max'      => 'nullable|numeric|min:0',
            'salario_oculto'   => 'nullable|boolean',
            'ocultar_empresa'  => 'nullable|boolean',
            'ocultar_endereco' => 'nullable|boolean',
            'cep'              => 'nullable|string|max:9',
            'logradouro'       => 'nullable|string|max:255',
            'numero'           => 'nullable|string|max:20',
            'bairro'           => 'nullable|string|max:100',
            'cidade'           => 'nullable|string|max:100',
            'estado'           => 'nullable|string|size:2',
            'genero'           => 'nullable|string|max:20',
            'turno'            => 'nullable|string|max:20',
            'horario_trabalho' => 'nullable|string|max:50',
            'expira_em'        => 'nullable|date',
        ]);
    }

    /** Traduz nomes do contrato para as colunas do banco. */
    private function mapToDb(array $data): array
    {
        $mapped = collect($data)->except(['beneficios', 'beneficios_texto', 'modalidade', 'numero_posicoes', 'salario_oculto', 'expira_em'])->all();

        if (array_key_exists('beneficios_texto', $data)) $mapped['beneficios']       = $data['beneficios_texto'];
        if (array_key_exists('modalidade', $data))       $mapped['regime_trabalho']  = $data['modalidade'];
        if (array_key_exists('numero_posicoes', $data))  $mapped['quantidade_vagas'] = $data['numero_posicoes'] ?? 1;
        if (array_key_exists('salario_oculto', $data))   $mapped['exibir_salario']   = !($data['salario_oculto'] ?? false);
        if (array_key_exists('expira_em', $data))        $mapped['data_fechamento']  = $data['expira_em'];

        return $mapped;
    }

    private function payload(Vaga $v, bool $full = false): array
    {
        $base = [
            'id'                   => $v->id,
            'titulo'               => $v->titulo,
            'status'               => self::STATUS_OUT[$v->status] ?? $v->status,
            'canal'                => $v->canal,
            'modalidade'           => $v->regime_trabalho,
            'tipo_contrato'        => $v->tipo_contrato,
            'cidade'               => $v->cidade,
            'estado'               => $v->estado,
            'numero_posicoes'      => $v->quantidade_vagas,
            'salario_min'          => $v->salario_min,
            'salario_max'          => $v->salario_max,
            'salario_oculto'       => !$v->exibir_salario,
            'candidatos_recebidos' => $v->envios_count ?? 0,
            'created_at'           => $v->created_at,
            'expira_em'            => $v->data_fechamento?->toDateString(),
        ];

        if (!$full) {
            return $base;
        }

        return [...$base,
            'descricao'        => $v->descricao,
            'requisitos'       => $v->requisitos,
            'beneficios_texto' => $v->beneficios,
            'beneficios'       => $v->relationLoaded('beneficiosCatalogo')
                ? $v->beneficiosCatalogo->map(fn($b) => ['id' => $b->id, 'nome' => $b->nome])
                : [],
            'nivel_vaga_id'    => $v->nivel_vaga_id,
            'ocultar_empresa'  => $v->ocultar_empresa,
            'ocultar_endereco' => $v->ocultar_endereco,
            'cep'              => $v->cep,
            'logradouro'       => $v->logradouro,
            'numero'           => $v->numero,
            'bairro'           => $v->bairro,
            'genero'           => $v->genero,
            'turno'            => $v->turno,
            'horario_trabalho' => $v->horario_trabalho,
        ];
    }

    private function gatePlano(int $empresaId)
    {
        $empresa = Empresa::find($empresaId);

        if ($empresa && $empresa->plano === 'basico') {
            return response()->json([
                'message'    => 'Publicação de vagas não disponível no Plano Básico.',
                'upgrade_to' => 'padrao',
            ], 402);
        }

        return null;
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
}
