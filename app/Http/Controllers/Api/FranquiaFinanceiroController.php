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
            'fornecedor_nome' => 'nullable|string|max:255',
            'observacao'      => 'nullable|string',
        ]);

        $conta = FranquiaContaPagar::create(array_merge($validated, [
            'franquia_id' => $franquiaId,
            'status'      => 'pendente',
        ]));

        return response()->json(['message' => 'Conta registrada.', 'data' => $conta], 201);
    }

    // PATCH /franquia/financeiro/contas-pagar/{id}/pagar
    public function pagarConta(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $conta = FranquiaContaPagar::where('franquia_id', $franquiaId)->findOrFail($id);
        $conta->update(['status' => 'pago', 'data_pagamento' => now()]);

        return response()->json(['message' => 'Pagamento registrado.', 'status' => 'pago']);
    }

    // GET /franquia/financeiro/taxas — percentuais vigentes para o tipo da franquia
    public function taxas(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $franquia   = \App\Models\Franquia::findOrFail($franquiaId);

        $valor = fn(string $categoria) => (float) (\App\Models\FinanceiroConfig::where('categoria', $categoria)
            ->where('tipo_franquia', $franquia->tipo)
            ->orderByDesc('created_at')
            ->value('valor') ?? 0);

        return response()->json(['data' => [
            'imposto'          => $valor('percentual_imposto'),
            'royalties'        => $valor('tx_royalties'),
            'marketing'        => $valor('tx_marketing'),
            'comissao'         => $valor('percentual_comissao'),
            'prazo_vencimento' => 30,
            'prazo_reposicao'  => 30,
        ]]);
    }

    // GET /franquia/financeiro/faturaveis — envios aprovados das vagas da franquia
    public function faturaveis(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $envios = \App\Models\Envio::with([
                'candidato.user:id,name',
                'vaga:id,titulo,empresa_id,nivel_vaga_id,franquia_id',
                'vaga.empresa:id,razao_social,prazo_vencimento_dias,reposicao_dias',
            ])
            ->whereHas('vaga', fn($q) => $q->where('franquia_id', $franquiaId))
            ->where(fn($q) => $q->where('status', 'aprovado')->orWhere('status_empresa', 'aprovado'))
            ->orderByDesc('updated_at')
            ->get();

        $faturadosIds = FranquiaContaReceber::where('franquia_id', $franquiaId)
            ->whereNotNull('envio_id')->pluck('envio_id')->flip();

        $taxas = \App\Models\EmpresaTaxaServico::all()
            ->keyBy(fn($t) => "{$t->empresa_id}-{$t->nivel_vaga_id}");

        return response()->json(['data' => $envios->map(function ($e) use ($faturadosIds, $taxas) {
            $key  = "{$e->vaga?->empresa_id}-{$e->vaga?->nivel_vaga_id}";
            $taxa = isset($taxas[$key]) ? $taxas[$key]->percentual : 100;

            return [
                'id'                 => $e->id,
                'empresa_nome'       => $e->vaga?->empresa?->razao_social,
                'candidato_nome'     => $e->candidato?->user?->name,
                'vaga_titulo'        => $e->vaga?->titulo,
                'salario_aprovado'   => $e->salario_aprovado !== null ? (string) $e->salario_aprovado : '',
                'taxa_servico'       => (float) $taxa,
                'prazo_vencimento'   => $e->vaga?->empresa?->prazo_vencimento_dias ?? 30,
                'prazo_reposicao'    => $e->vaga?->empresa?->reposicao_dias ?? 30,
                'data_aprovacao'     => $e->updated_at,
                'status_faturamento' => isset($faturadosIds[$e->id]) ? 'faturado' : 'pendente',
            ];
        })]);
    }

    // POST /franquia/financeiro/faturar — gera contas a receber dos envios aprovados
    public function faturar(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $data = $request->validate([
            'itens'                     => 'required|array|min:1',
            'itens.*.envio_id'          => 'required|integer|exists:envios,id',
            'itens.*.salario'           => 'required|numeric|min:0',
            'itens.*.taxa_servico'      => 'required|numeric|min:0',
            'itens.*.imposto_perc'      => 'nullable|numeric|min:0',
            'itens.*.royalties_perc'    => 'nullable|numeric|min:0',
            'itens.*.marketing_perc'    => 'nullable|numeric|min:0',
            'itens.*.comissao_perc'     => 'nullable|numeric|min:0',
            'itens.*.servicos_credito'  => 'nullable|numeric|min:0',
            'itens.*.servicos_debito'   => 'nullable|numeric|min:0',
            'itens.*.data_vencimento'   => 'nullable|date',
            'itens.*.data_reposicao'    => 'nullable|date',
        ]);

        $criadas = [];

        foreach ($data['itens'] as $item) {
            $envio = \App\Models\Envio::with(['candidato.user:id,name', 'vaga.empresa:id,razao_social'])
                ->whereHas('vaga', fn($q) => $q->where('franquia_id', $franquiaId))
                ->findOrFail($item['envio_id']);

            if (FranquiaContaReceber::where('envio_id', $envio->id)->exists()) {
                continue; // já faturado
            }

            $salario  = (float) $item['salario'];
            $taxa     = (float) $item['taxa_servico'];
            $bruto    = round($salario * $taxa / 100, 2)
                      + (float) ($item['servicos_credito'] ?? 0)
                      - (float) ($item['servicos_debito'] ?? 0);

            $impostoPerc   = (float) ($item['imposto_perc'] ?? 0);
            $impostoValor  = round($bruto * $impostoPerc / 100, 2);
            $liquidoBase   = $bruto - $impostoValor;

            // Comissão bruta da franquia sobre o valor base (após imposto)
            $comissaoPerc  = (float) ($item['comissao_perc'] ?? 0);
            $comissaoValor = round($liquidoBase * $comissaoPerc / 100, 2);

            // Royalties e marketing incidem sobre a comissão da franquia, não sobre o valor base
            $royaltiesPerc  = (float) ($item['royalties_perc'] ?? 0);
            $royaltiesValor = round($comissaoValor * $royaltiesPerc / 100, 2);
            $marketingPerc  = (float) ($item['marketing_perc'] ?? 0);
            $marketingValor = round($comissaoValor * $marketingPerc / 100, 2);

            // Comissão líquida da franquia, já descontados royalties e marketing
            $liquido = round($comissaoValor - $royaltiesValor - $marketingValor, 2);

            $criadas[] = FranquiaContaReceber::create([
                'franquia_id'      => $franquiaId,
                'envio_id'         => $envio->id,
                'candidato_nome'   => $envio->candidato?->user?->name,
                'vaga_nome'        => $envio->vaga?->titulo,
                'empresa_nome'     => $envio->vaga?->empresa?->razao_social,
                'salario'          => $salario,
                'taxa_servico'     => $taxa,
                'valor_bruto'      => $bruto,
                'imposto_perc'     => $impostoPerc,
                'imposto_valor'    => $impostoValor,
                'royalties_perc'   => $royaltiesPerc,
                'royalties_valor'  => $royaltiesValor,
                'marketing_perc'   => $marketingPerc,
                'marketing_valor'  => $marketingValor,
                'comissao_perc'    => $comissaoPerc,
                'comissao_valor'   => $comissaoValor,
                'valor_liquido'    => $liquido,
                'data_faturamento' => now(),
                'data_vencimento'  => $item['data_vencimento'] ?? now()->addDays(30),
                'data_reposicao'   => $item['data_reposicao'] ?? null,
                'status'           => 'pendente',
            ]);
        }

        return response()->json([
            'message' => count($criadas) . ' faturamento(s) realizado(s) com sucesso.',
            'data'    => $criadas,
        ], 201);
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
