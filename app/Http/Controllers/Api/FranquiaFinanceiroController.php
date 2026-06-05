<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\FranquiaContaPagar;
use App\Models\FranquiaContaReceber;
use App\Models\FranquiaFaturamento;
use App\Models\FranquiaNotaFiscal;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FranquiaFinanceiroController extends Controller
{
    use HasTokenContext;

    // GET /franquia/financeiro/caixa
    public function caixa(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $totalReceber = FranquiaContaReceber::where('franquia_id', $franquiaId)
            ->where('status', 'pendente')->sum('valor_liquido');
        $totalPagar   = FranquiaContaPagar::where('franquia_id', $franquiaId)
            ->where('status', 'pendente')->sum('valor');
        $saldoAtual   = FranquiaFaturamento::where('franquia_id', $franquiaId)
            ->where('status', 'pago')->sum('valor');

        $ultimas = FranquiaContaPagar::where('franquia_id', $franquiaId)
            ->orderByDesc('created_at')->limit(5)
            ->get()->map(fn($c) => [
                'id'        => $c->id,
                'tipo'      => 'saida',
                'descricao' => $c->descricao,
                'valor'     => $c->valor,
                'data'      => $c->data_vencimento,
            ]);

        return response()->json(['data' => [
            'saldo_atual'           => $saldoAtual,
            'total_a_receber'       => $totalReceber,
            'total_a_pagar'         => $totalPagar,
            'resultado_projetado'   => $saldoAtual + $totalReceber - $totalPagar,
            'ultimas_movimentacoes' => $ultimas,
        ]]);
    }

    // GET /franquia/financeiro/contas-receber
    public function contasReceber(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $query = FranquiaContaReceber::where('franquia_id', $franquiaId)
            ->where('is_sstart', false);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $contas = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $contas->items(),
            'meta' => ['total' => $contas->total(), 'per_page' => $contas->perPage(),
                       'current_page' => $contas->currentPage(), 'last_page' => $contas->lastPage()],
        ]);
    }

    // GET /franquia/financeiro/contas-receber/sstart
    public function contasReceberSStart(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $contas = FranquiaContaReceber::where('franquia_id', $franquiaId)
            ->where('is_sstart', true)
            ->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $contas->items(),
            'meta' => ['total' => $contas->total(), 'per_page' => $contas->perPage(),
                       'current_page' => $contas->currentPage(), 'last_page' => $contas->lastPage()],
        ]);
    }

    // GET /franquia/financeiro/contas-pagar
    public function contasPagar(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $query = FranquiaContaPagar::where('franquia_id', $franquiaId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $contas = $query->orderBy('data_vencimento')->paginate(20);

        return response()->json([
            'data' => $contas->items(),
            'meta' => ['total' => $contas->total(), 'per_page' => $contas->perPage(),
                       'current_page' => $contas->currentPage(), 'last_page' => $contas->lastPage()],
        ]);
    }

    // POST /franquia/financeiro/contas-pagar
    public function storeContaPagar(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $validated = $request->validate([
            'descricao'       => 'required|string|max:255',
            'valor'           => 'required|numeric|min:0',
            'data_vencimento' => 'required|date',
            'categoria'       => 'nullable|string|max:50',
            'fornecedor_id'   => 'nullable|integer',
        ]);

        $conta = FranquiaContaPagar::create(array_merge($validated, [
            'franquia_id' => $franquiaId,
            'status'      => 'pendente',
        ]));

        return response()->json(['message' => 'Conta registrada.', 'data' => ['id' => $conta->id, 'status' => 'pendente']], 201);
    }

    // PATCH /franquia/financeiro/contas-pagar/{id}/pagar
    public function pagarConta(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $conta = FranquiaContaPagar::where('franquia_id', $franquiaId)->findOrFail($id);
        $conta->update(['status' => 'pago', 'data_pagamento' => now()]);

        return response()->json(['message' => 'Pagamento registrado.', 'status' => 'pago']);
    }

    // GET /franquia/financeiro/faturamento
    public function faturamento(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $faturamentos = FranquiaFaturamento::where('franquia_id', $franquiaId)
            ->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $faturamentos->items(),
            'meta' => ['total' => $faturamentos->total(), 'per_page' => $faturamentos->perPage(),
                       'current_page' => $faturamentos->currentPage(), 'last_page' => $faturamentos->lastPage()],
        ]);
    }

    // GET /franquia/financeiro/fiscal
    public function fiscal(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $notas = FranquiaNotaFiscal::where('franquia_id', $franquiaId)
            ->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $notas->items(),
            'meta' => ['total' => $notas->total(), 'per_page' => $notas->perPage(),
                       'current_page' => $notas->currentPage(), 'last_page' => $notas->lastPage()],
        ]);
    }

    // GET /franquia/financeiro/relatorios
    public function relatorios(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $periodo = $request->query('periodo', 'mes');
        $fim     = Carbon::now()->endOfDay();
        $inicio  = match ($periodo) {
            'trimestre' => Carbon::now()->subMonths(3)->startOfDay(),
            'semestre'  => Carbon::now()->subMonths(6)->startOfDay(),
            'ano'       => Carbon::now()->subYear()->startOfDay(),
            default     => Carbon::now()->startOfMonth(),
        };

        if ($request->filled('data_inicio')) $inicio = Carbon::parse($request->data_inicio)->startOfDay();
        if ($request->filled('data_fim'))    $fim     = Carbon::parse($request->data_fim)->endOfDay();

        $receber = FranquiaContaReceber::where('franquia_id', $franquiaId)
            ->whereBetween('created_at', [$inicio, $fim]);

        $pagar = FranquiaContaPagar::where('franquia_id', $franquiaId)
            ->whereBetween('created_at', [$inicio, $fim]);

        return response()->json(['data' => [
            'periodo'  => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()],
            'receitas' => [
                'total'      => $receber->sum('valor_liquido'),
                'comissoes'  => $receber->sum('comissao_valor'),
                'taxas'      => 0,
                'outros'     => 0,
            ],
            'despesas' => [
                'total'          => $pagar->sum('valor'),
                'infraestrutura' => $pagar->where('categoria', 'infraestrutura')->sum('valor'),
                'marketing'      => $pagar->where('categoria', 'marketing')->sum('valor'),
                'pessoal'        => $pagar->where('categoria', 'pessoal')->sum('valor'),
                'outros'         => $pagar->whereNotIn('categoria', ['infraestrutura', 'marketing', 'pessoal'])->sum('valor'),
            ],
            'resultado_liquido' => $receber->sum('valor_liquido') - $pagar->sum('valor'),
        ]]);
    }
}
