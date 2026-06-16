<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComissaoTipo;
use App\Models\FinanceiroConfig;
use App\Models\FranquiaContaPagar;
use App\Models\FranquiaContaReceber;
use App\Models\FranquiaFaturamento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFinanceiroController extends Controller
{
    /* ─── Configurações por tipo de franquia ─────────────────────────── */

    public function indexConfigs(string $categoria)
    {
        $this->validateCategoria($categoria);

        return response()->json(
            FinanceiroConfig::where('categoria', $categoria)
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function storeConfig(Request $request, string $categoria)
    {
        $this->validateCategoria($categoria);

        $data = $request->validate([
            'tipo_franquia' => 'required|in:premium,start,s_start',
            'valor'         => 'required|numeric|min:0',
        ]);

        $exists = FinanceiroConfig::where('categoria', $categoria)
            ->where('tipo_franquia', $data['tipo_franquia'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Já existe uma configuração de ' . $categoria . ' para este tipo de franquia. Use a edição para alterar o valor.',
            ], 422);
        }

        $config = FinanceiroConfig::create([
            ...$data,
            'categoria'  => $categoria,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($config, 201);
    }

    public function updateConfig(Request $request, string $categoria, FinanceiroConfig $config)
    {
        $this->validateCategoria($categoria);

        if ($config->categoria !== $categoria) {
            return response()->json(['message' => 'Registro não pertence a esta categoria.'], 403);
        }

        $data = $request->validate([
            'tipo_franquia' => 'required|in:premium,start,s_start',
            'valor'         => 'required|numeric|min:0',
        ]);

        $config->update($data);

        return response()->json($config);
    }

    public function destroyConfig(string $categoria, FinanceiroConfig $config)
    {
        $this->validateCategoria($categoria);

        if ($config->categoria !== $categoria) {
            return response()->json(['message' => 'Registro não pertence a esta categoria.'], 403);
        }

        $config->delete();

        return response()->json(['message' => 'Registro excluído com sucesso.']);
    }

    private function validateCategoria(string $categoria): void
    {
        abort_unless(
            in_array($categoria, FinanceiroConfig::CATEGORIAS, true),
            404,
            'Categoria inválida.'
        );
    }

    /* ─── Tipos de comissão (por indicação) ──────────────────────────── */

    public function indexComissaoTipos()
    {
        return response()->json(ComissaoTipo::orderBy('tipo')->get());
    }

    public function updateComissaoTipo(Request $request, ComissaoTipo $comissaoTipo)
    {
        $data = $request->validate([
            'percentual' => 'required|numeric|min:0|max:100',
        ]);

        $comissaoTipo->update($data);

        return response()->json($comissaoTipo);
    }

    /* ─── Contas a receber (consolidado de todas as franquias) ───────── */

    public function contasReceber(Request $request)
    {
        $query = FranquiaContaReceber::query()
            ->with('franquia:id,nome')
            ->orderByDesc('created_at');

        if ($request->filled('franquia_id')) {
            $query->where('franquia_id', $request->franquia_id);
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('status', $request->status);
        }

        $contas = $query->paginate(20);

        $totais = FranquiaContaReceber::query()
            ->when($request->filled('franquia_id'), fn($q) => $q->where('franquia_id', $request->franquia_id))
            ->when($request->filled('status') && $request->status !== 'todos', fn($q) => $q->where('status', $request->status))
            ->selectRaw('COALESCE(SUM(valor_bruto),0) as bruto, COALESCE(SUM(valor_liquido),0) as liquido')
            ->first();

        return response()->json([
            'data'   => $contas->items(),
            'totais' => ['bruto' => (float) $totais->bruto, 'liquido' => (float) $totais->liquido],
            'meta'   => ['total' => $contas->total(), 'per_page' => $contas->perPage(),
                         'current_page' => $contas->currentPage(), 'last_page' => $contas->lastPage()],
        ]);
    }

    /* ─── Contas a pagar (consolidado de todas as franquias) ─────────── */

    public function contasPagar(Request $request)
    {
        $query = FranquiaContaPagar::query()
            ->with('franquia:id,nome')
            ->orderBy('data_vencimento');

        if ($request->filled('franquia_id')) {
            $query->where('franquia_id', $request->franquia_id);
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('status', $request->status);
        }

        $contas = $query->paginate(20);

        $total = FranquiaContaPagar::query()
            ->when($request->filled('franquia_id'), fn($q) => $q->where('franquia_id', $request->franquia_id))
            ->when($request->filled('status') && $request->status !== 'todos', fn($q) => $q->where('status', $request->status))
            ->sum('valor');

        return response()->json([
            'data'   => $contas->items(),
            'totais' => ['valor' => (float) $total],
            'meta'   => ['total' => $contas->total(), 'per_page' => $contas->perPage(),
                         'current_page' => $contas->currentPage(), 'last_page' => $contas->lastPage()],
        ]);
    }

    /* ─── Faturamento (cobranças da franqueadora às franquias) ───────── */

    public function indexFranquiaFaturamentos(Request $request)
    {
        $query = FranquiaFaturamento::query()
            ->with('franquia:id,nome')
            ->orderByDesc('created_at');

        if ($request->filled('franquia_id')) {
            $query->where('franquia_id', $request->franquia_id);
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('status', $request->status);
        }

        if ($request->filled('tipo') && $request->tipo !== 'todos') {
            $query->where('tipo', $request->tipo);
        }

        $faturamentos = $query->paginate(20);

        $totais = FranquiaFaturamento::query()
            ->when($request->filled('franquia_id'), fn($q) => $q->where('franquia_id', $request->franquia_id))
            ->when($request->filled('tipo') && $request->tipo !== 'todos', fn($q) => $q->where('tipo', $request->tipo))
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END),0) as pago,
                         COALESCE(SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END),0) as pendente")
            ->first();

        return response()->json([
            'data'   => $faturamentos->items(),
            'totais' => ['pago' => (float) $totais->pago, 'pendente' => (float) $totais->pendente],
            'meta'   => ['total' => $faturamentos->total(), 'per_page' => $faturamentos->perPage(),
                         'current_page' => $faturamentos->currentPage(), 'last_page' => $faturamentos->lastPage()],
        ]);
    }

    public function storeFranquiaFaturamento(Request $request)
    {
        $data = $request->validate([
            'franquia_id'     => 'required|integer|exists:franquias,id',
            'descricao'       => 'required|string|max:255',
            'tipo'            => 'required|in:comissao_vaga,taxa_mensal,royalties,outros',
            'valor'           => 'required|numeric|min:0',
            'data_referencia' => 'nullable|date',
            'empresa_nome'    => 'nullable|string|max:255',
        ]);

        $faturamento = FranquiaFaturamento::create([...$data, 'status' => 'pendente']);

        return response()->json($faturamento->load('franquia:id,nome'), 201);
    }

    public function updateFranquiaFaturamentoStatus(Request $request, FranquiaFaturamento $faturamento)
    {
        $data = $request->validate(['status' => 'required|in:pendente,pago']);

        $faturamento->update([
            'status'         => $data['status'],
            'data_pagamento' => $data['status'] === 'pago' ? now() : null,
        ]);

        return response()->json($faturamento->load('franquia:id,nome'));
    }

    /* ─── Relatório de faturamento (todas as franquias) ──────────────── */

    public function faturamentos(Request $request)
    {
        $query = DB::table('franquia_contas_receber as cr')
            ->join('franquias as f', 'f.id', '=', 'cr.franquia_id')
            ->select([
                'cr.id',
                'cr.empresa_nome',
                'cr.candidato_nome',
                'cr.vaga_nome',
                DB::raw('COALESCE(cr.franchise_nome, f.nome) as franchise_nome'),
                'cr.salario',
                'cr.taxa_servico',
                'cr.valor_bruto',
                'cr.valor_liquido',
                'cr.status',
                'cr.data_faturamento',
            ])
            ->whereNotNull('cr.data_faturamento');

        if ($request->filled('empresa')) {
            $query->where('cr.empresa_nome', 'like', '%' . $request->empresa . '%');
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('cr.status', $request->status);
        }

        if ($request->filled('mes')) { // formato YYYY-MM
            $query->where('cr.data_faturamento', 'like', $request->mes . '%');
        }

        if ($request->filled('franquia_id')) {
            $query->where('cr.franquia_id', $request->franquia_id);
        }

        $items = $query->orderByDesc('cr.data_faturamento')->get();

        return response()->json([
            'data'   => $items,
            'totais' => [
                'bruto'   => round($items->sum('valor_bruto'), 2),
                'liquido' => round($items->sum('valor_liquido'), 2),
            ],
        ]);
    }
}
