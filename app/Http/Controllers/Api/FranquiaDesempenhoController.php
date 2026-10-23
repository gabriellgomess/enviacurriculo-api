<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Concerns\PeriodoDesempenho;
use App\Http\Controllers\Controller;
use App\Models\Franquia;
use App\Models\Vaga;
use App\Services\Financeiro\ValoresColocacoes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Desempenho da Franquia — a versão por unidade do Desempenho da Rede.
 *
 * Mesmas definições do admin (ver AdminDesempenhoRedeController e o trait
 * PeriodoDesempenho): vínculo pela data de criação, contratação pela data de
 * admissão (sem admissão = estimado no mês corrente), reposição pela data de
 * saída, conversão = admitidos ÷ vínculos. Valores em dinheiro calculados pelo
 * motor das colocações, sem depender de fatura emitida.
 *
 * Escopo da unidade X:
 *  - Minha produção: vínculos que X encaminhou (envios.franquia_id = X).
 *  - De terceiros (só Premium): vínculos de outras unidades nas vagas de X
 *    (vagas.franquia_id = X). Start não é dona de vaga, então só tem a própria.
 *  - Minha comissão: a de quem produziu quando X produziu, ou a de dona da
 *    vaga quando outra unidade fechou a vaga de X. Valor líquido (após
 *    royalties e marketing), que é o que fica com a unidade.
 */
class FranquiaDesempenhoController extends Controller
{
    use HasTokenContext;
    use PeriodoDesempenho;

    private const PENDENTES   = "e.status IN ('enviado','visualizado','pendente')";
    private const EM_PROCESSO = "e.status IN ('em_processo','em_entrevista')";
    private const FECHADAS    = "e.status IN ('aprovado','reposicao')";
    private const COM_RETORNO = "e.status IN ('aprovado','reposicao','reprovado','desistiu')";
    private const DIAS_SEM_FECHAR = 90;

    private int $franquiaId = 0;
    private bool $premium = false;

    // GET /franquia/indicadores/desempenho
    public function index(Request $request)
    {
        $this->validar($request);
        $franquia   = $this->iniciar($request);
        $intervalos = $this->intervalos($request);
        $anterior   = $this->intervaloAnterior($intervalos);
        $this->prepararDatas();

        $valores    = app(ValoresColocacoes::class);
        $colocacoes = $this->comMinhaComissao($valores->calcular(
            $this->contratacoesDe($this->base($request), $intervalos), $this->dataAprovacao, $this->dataReferencia));
        $reposicoes = $this->comMinhaComissao($valores->calcular(
            $this->reposicoesDe($this->base($request), $intervalos), $this->dataAprovacao, $this->dataReferencia));

        $resumo   = $this->resumo($request, $intervalos);
        $empresas = $this->empresas($request, $intervalos, $colocacoes);

        return response()->json(['data' => [
            'tipo'     => $this->premium ? 'premium' : 'start',
            'franquia' => [
                'id'     => $franquia->id,
                'nome'   => $franquia->nome,
                'codigo' => $franquia->codigo,
            ],
            'cards'          => $this->cards($request, $intervalos, $anterior, $colocacoes, $reposicoes, $resumo),
            'comissoes'      => $this->listaComissoes($colocacoes),
            'pagas'          => $this->pagas($request, $intervalos),
            'producao_vagas' => $this->premium ? $this->producaoNasMinhasVagas($request, $intervalos, $colocacoes) : [],
            'empresas'       => $empresas,
            'funil'          => $this->funil($request, $intervalos),
            'evolucao'       => $this->evolucao($request, $this->anoEvolucao($request, $intervalos)),
            'atencao'        => $this->atencao($request, $empresas, $colocacoes),
            'opcoes'         => $this->opcoes(),
            'comparacao'     => $anterior ? [
                'de'  => $anterior[0][0]->toDateString(),
                'ate' => $anterior[0][1]->toDateString(),
            ] : null,
            'estimado_desde'   => $this->estimadoDesde,
            'anos_disponiveis' => $this->anosDisponiveis(),
        ]]);
    }

    /**
     * Listas completas para exportar (PDF / Excel) os cards de vínculos e
     * reposições. tipo_detalhe: vinculos | reposicoes
     */
    // GET /franquia/indicadores/desempenho/detalhes
    public function detalhes(Request $request)
    {
        $request->validate(['tipo_detalhe' => 'required|in:vinculos,reposicoes']);
        $this->validar($request);
        $this->iniciar($request);
        $intervalos = $this->intervalos($request);
        $this->prepararDatas();

        if ($request->tipo_detalhe === 'reposicoes') {
            $reposicoes = $this->comMinhaComissao(app(ValoresColocacoes::class)->calcular(
                $this->reposicoesDe($this->base($request), $intervalos), $this->dataAprovacao, $this->dataReferencia))
                ->keyBy('id');

            $itens = $this->detalheEnvios($reposicoes->keys()->all())
                ->map(fn($r) => [
                    'candidato'     => $r->candidato ?: 'Não informado',
                    'vaga'          => $r->vaga ?: '—',
                    'empresa'       => $r->empresa ?: '—',
                    'unidade'       => $r->unidade ?: 'Feed / Sem unidade',
                    'comissao'      => (float) ($reposicoes[$r->id]['minha_comissao'] ?? 0),
                    'data_admissao' => $r->data_admissao ? Carbon::parse($r->data_admissao)->toDateString() : null,
                    'data_saida'    => $r->data_saida ? Carbon::parse($r->data_saida)->toDateString() : null,
                ])
                ->sortByDesc('comissao')->values();

            return response()->json(['data' => $itens]);
        }

        $q = $this->base($request);
        $this->aplicarPeriodo($q, 'e.created_at', $intervalos);

        $itens = $q->join('empresas as emp', 'emp.id', '=', 'v.empresa_id')
            ->leftJoin('candidatos as c', 'c.id', '=', 'e.candidato_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->orderByDesc('e.created_at')
            ->limit(2000)
            ->get([
                'e.status', 'e.created_at', 'e.franquia_id',
                'u.name as candidato', 'v.titulo as vaga',
                DB::raw('COALESCE(emp.nome_fantasia, emp.razao_social) as empresa'),
                'f.nome as unidade',
            ])
            ->map(fn($r) => [
                'candidato' => $r->candidato ?: 'Não informado',
                'vaga'      => $r->vaga ?: '—',
                'empresa'   => $r->empresa ?: '—',
                'unidade'   => $r->unidade ?: 'Feed / Sem unidade',
                'origem'    => (int) $r->franquia_id === $this->franquiaId ? 'propria' : 'terceiros',
                'status'    => $r->status,
                'criado_em' => Carbon::parse($r->created_at)->format('Y-m-d H:i'),
            ]);

        return response()->json(['data' => $itens]);
    }

    /* ─── Base ───────────────────────────────────────────────────────── */

    private function validar(Request $request): void
    {
        $request->validate([
            'de'            => 'nullable|date',
            'ate'           => 'nullable|date',
            'meses'         => 'nullable|array',
            'meses.*'       => 'date_format:Y-m',
            'anos'          => 'nullable|array',
            'anos.*'        => 'integer|min:2000|max:2100',
            'ano_evolucao'  => 'nullable|integer|min:2000|max:2100',
            'empresa_ids'   => 'nullable|array',
            'empresa_ids.*' => 'integer',
            'unidade_ids'   => 'nullable|array',
            'unidade_ids.*' => 'integer',
        ]);
    }

    private function iniciar(Request $request): Franquia
    {
        $this->franquiaId = $this->tokenContextId($request);
        $franquia = Franquia::findOrFail($this->franquiaId);
        $this->premium = $franquia->tipo === 'premium';
        return $franquia;
    }

    /**
     * Vínculos da unidade com os filtros aplicados. "Unidades" na Premium é
     * quem produziu nas vagas dela; na Start, a unidade dona da vaga.
     */
    private function base(Request $request)
    {
        $x = $this->franquiaId;

        $q = DB::table('envios as e')
            ->join('vagas as v', 'v.id', '=', 'e.vaga_id')
            ->leftJoin('franquias as f', 'f.id', '=', 'e.franquia_id')
            ->whereNull('v.deleted_at');

        $this->premium
            ? $q->where(fn($w) => $w->where('e.franquia_id', $x)->orWhere('v.franquia_id', $x))
            : $q->where('e.franquia_id', $x);

        if ($ids = $this->ids($request, 'empresa_ids')) {
            $q->whereIn('v.empresa_id', $ids);
        }
        if ($ids = $this->ids($request, 'unidade_ids')) {
            $q->whereIn($this->premium ? 'e.franquia_id' : 'v.franquia_id', $ids);
        }

        return $q;
    }

    /** Só o que a própria unidade encaminhou. */
    private function propria($q)
    {
        return $q->where('e.franquia_id', $this->franquiaId);
    }

    /** O que outras unidades (ou o feed) produziram nas vagas da unidade. */
    private function terceiros($q)
    {
        return $q->where('v.franquia_id', $this->franquiaId)
            ->where(fn($w) => $w->whereNull('e.franquia_id')->orWhere('e.franquia_id', '<>', $this->franquiaId));
    }

    /**
     * Acrescenta a cada colocação de onde ela veio (própria / terceiros) e a
     * comissão que cabe a esta unidade.
     */
    private function comMinhaComissao(Collection $colocacoes): Collection
    {
        return $colocacoes->map(function ($c) {
            $propria = (int) $c['produtora_id'] === $this->franquiaId;
            return $c + [
                'origem'         => $propria ? 'propria' : 'terceiros',
                'minha_comissao' => $propria ? $c['comissao_produtora'] : $c['comissao_dona'],
                'meu_perc'       => $propria ? $c['comissao_produtora_perc'] : $c['comissao_dona_perc'],
            ];
        });
    }

    /**
     * Soma de valores em dinheiro arredondando cada colocação aos centavos
     * antes — o mesmo que a lista de comissões mostra linha a linha. Somar
     * com precisão total e arredondar no fim deixava o card um centavo
     * diferente do total da lista (ex.: R$ 3.960,20 x R$ 3.960,21).
     */
    private function centavos(Collection $colocacoes, string $campo): float
    {
        return round($colocacoes->sum(fn($c) => round($c[$campo], 2)), 2);
    }

    /* ─── Seções ─────────────────────────────────────────────────────── */

    /** Vínculos do período (coorte): total e as contagens do funil. */
    private function resumo(Request $request, ?array $periodo): array
    {
        $q = $this->base($request);
        $this->aplicarPeriodo($q, 'e.created_at', $periodo);

        $r = $q->selectRaw('COUNT(*) as vinculos')
            ->selectRaw('SUM(e.franquia_id = ?) as propria', [$this->franquiaId])
            ->selectRaw('SUM(' . self::COM_RETORNO . ') as com_retorno')
            ->selectRaw('SUM(' . self::FECHADAS . ') as fechadas')
            ->selectRaw("SUM(e.status = 'reposicao') as reposicoes")
            ->selectRaw("SUM(e.status = 'reprovado') as reprovados")
            ->selectRaw("SUM(e.status = 'desistiu') as desistiu")
            ->selectRaw('COUNT(DISTINCT e.vaga_id) as vagas_com_vinculo')
            ->first();

        return array_map('intval', (array) $r);
    }

    /** Admitidos do período (pela data de admissão) e a conversão. */
    private function conversao(Request $request, ?array $periodo): array
    {
        $q = $this->base($request);
        $this->aplicarPeriodo($q, 'e.created_at', $periodo);
        $vinculos = (clone $q)->count();

        $fechadas = (int) $this->contratacoesDe($this->base($request), $periodo)
            ->whereNotNull('e.data_admissao')->count();

        return [
            'vinculos'  => $vinculos,
            'fechadas'  => $fechadas,
            'conversao' => $vinculos ? round($fechadas / $vinculos * 100, 1) : null,
        ];
    }

    private function situacoes(Request $request, ?array $periodo, callable $recorte): array
    {
        $q = $recorte($this->base($request));
        $this->aplicarPeriodo($q, 'e.created_at', $periodo);

        $r = $q->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(' . self::PENDENTES . ') as pendentes')
            ->selectRaw('SUM(' . self::EM_PROCESSO . ') as em_processo')
            ->selectRaw("SUM(e.status = 'desistiu') as desistiu")
            ->selectRaw("SUM(e.status = 'reprovado') as reprovados")
            ->selectRaw('SUM(' . self::FECHADAS . ') as aprovados')
            ->first();

        return array_map('intval', (array) $r);
    }

    private function cards(Request $request, ?array $intervalos, ?array $anterior, Collection $colocacoes, Collection $reposicoes, array $resumo): array
    {
        $propria   = $colocacoes->where('origem', 'propria');
        $terceiros = $colocacoes->where('origem', 'terceiros');
        $soma      = fn(Collection $l, string $campo) => $this->centavos($l, $campo);

        $meta = $this->meta($intervalos);
        $realizado = $soma($propria->where('estimado', false), 'faturado');
        $estimado  = $soma($propria->where('estimado', true), 'faturado');

        // Janelas fixas (hoje / semana / mês corrente), independentes do filtro de período
        $janelas = $this->base($request)
            ->where('e.created_at', '>=', now()->startOfMonth()->min(now()->startOfWeek()))
            ->selectRaw('SUM(e.created_at >= ?) as hoje', [now()->startOfDay()])
            ->selectRaw('SUM(e.created_at >= ?) as semana', [now()->startOfWeek()])
            ->selectRaw('SUM(e.created_at >= ?) as mes', [now()->startOfMonth()])
            ->first();

        $conv = $this->conversao($request, $intervalos);
        $convAntes = $anterior ? $this->conversao($request, $anterior) : null;

        return [
            'comissao' => [
                'total'       => $soma($colocacoes, 'minha_comissao'),
                'propria'     => $soma($propria, 'minha_comissao'),
                'terceiros'   => $soma($terceiros, 'minha_comissao'),
                'estimado'    => $soma($colocacoes->where('estimado', true), 'minha_comissao'),
                'colocacoes'  => $colocacoes->count(),
                'admitidas'   => $colocacoes->where('estimado', false)->count(),
                'aguardando'  => $colocacoes->where('estimado', true)->count(),
                // Sem salário ou sem taxa: ficam fora dos valores (a tela avisa)
                'incompletas' => $colocacoes->where('completa', false)->count(),
            ],
            // A meta (em dinheiro) é de faturamento da própria produção — o
            // mesmo critério do valor_atual das metas no Dashboard
            'meta' => [
                'valor'     => $meta,
                'realizado' => $realizado,
                'estimado'  => $estimado,
                'perc'      => $meta ? round(($realizado + $estimado) / $meta * 100, 1) : null,
                'terceiros' => $soma($terceiros, 'faturado'),
            ],
            'vinculos' => [
                'periodo' => $resumo['vinculos'],
                'hoje'    => (int) $janelas->hoje,
                'semana'  => (int) $janelas->semana,
                'mes'     => (int) $janelas->mes,
            ],
            // Currículos que a própria unidade encaminhou no período
            'envios'     => $resumo['propria'],
            'reposicoes' => [
                'total'    => $reposicoes->count(),
                'comissao' => $soma($reposicoes, 'minha_comissao'),
            ],
            'situacoes' => [
                'propria'   => $this->situacoes($request, $intervalos, fn($q) => $this->propria($q)),
                'terceiros' => $this->premium ? $this->situacoes($request, $intervalos, fn($q) => $this->terceiros($q)) : null,
            ],
            'conversao' => $conv + [
                'aguardando' => $colocacoes->where('estimado', true)->count(),
                'anterior'   => $convAntes['conversao'] ?? null,
                'variacao'   => $convAntes && $conv['conversao'] !== null && $convAntes['conversao'] !== null
                    ? round($conv['conversao'] - $convAntes['conversao'], 1) : null,
            ],
        ];
    }

    /** Metas em dinheiro da unidade que tocam o período (mesmo critério do admin). */
    private function meta(?array $intervalos): ?float
    {
        $q = DB::table('metas_franquias as m')
            ->leftJoin('tipos_metas as t', 't.id', '=', 'm.tipo_meta_id')
            ->where('m.franquia_id', $this->franquiaId)
            ->where(fn($w) => $w->whereNull('m.tipo_meta_id')->orWhere('t.unidade', 'moeda'))
            ->whereIn('m.status', ['ativa', 'concluida']);

        if ($intervalos) {
            $q->where(function ($w) use ($intervalos) {
                foreach ($intervalos as [$de, $ate]) {
                    $w->orWhere(fn($x) => $x
                        ->where(fn($y) => $y->whereNull('m.data_inicio')->orWhere('m.data_inicio', '<=', $ate->toDateString()))
                        ->where(fn($y) => $y->whereNull('m.data_fim')->orWhere('m.data_fim', '>=', $de->toDateString())));
                }
            });
        }

        $total = (float) $q->sum('m.valor_meta');
        return $total > 0 ? round($total, 2) : null;
    }

    /** Candidato, vaga, empresa e unidade de uma lista de envios. */
    private function detalheEnvios(array $ids): Collection
    {
        if (!$ids) {
            return collect();
        }
        return DB::table('envios as e')
            ->whereIn('e.id', $ids)
            ->join('vagas as v', 'v.id', '=', 'e.vaga_id')
            ->leftJoin('empresas as emp', 'emp.id', '=', 'v.empresa_id')
            ->leftJoin('candidatos as c', 'c.id', '=', 'e.candidato_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('franquias as f', 'f.id', '=', 'e.franquia_id')
            ->get([
                'e.id', 'e.data_admissao', 'e.data_saida',
                'u.name as candidato', 'v.titulo as vaga',
                DB::raw('COALESCE(emp.nome_fantasia, emp.razao_social) as empresa'),
                'f.nome as unidade', 'f.tipo as unidade_tipo',
            ]);
    }

    /** Uma linha por colocação com a comissão desta unidade. */
    private function listaComissoes(Collection $colocacoes): array
    {
        $porId = $colocacoes->keyBy('id');

        return $this->detalheEnvios($porId->keys()->all())
            ->map(function ($r) use ($porId) {
                $c = $porId[$r->id];
                return [
                    'id'            => $r->id,
                    'candidato'     => $r->candidato ?: 'Não informado',
                    'vaga'          => $r->vaga ?: '—',
                    'empresa'       => $r->empresa ?: '—',
                    'unidade'       => $r->unidade ?: 'Feed / Sem unidade',
                    'unidade_tipo'  => $r->unidade_tipo,
                    'origem'        => $c['origem'],
                    'faturado'      => $c['faturado'],
                    'perc'          => $c['meu_perc'],
                    'comissao'      => round($c['minha_comissao'], 2),
                    'completa'      => $c['completa'],
                    'estimado'      => $c['estimado'],
                    'data_admissao' => $r->data_admissao ? Carbon::parse($r->data_admissao)->toDateString() : null,
                ];
            })
            ->sortByDesc(fn($l) => $l['data_admissao'] ?? '9999')
            ->values()
            ->all();
    }

    /**
     * Comissões já pagas: o "pago" só existe nos lançamentos de Contas a
     * Receber (o cálculo ao vivo não sabe o que foi pago). Período pelo
     * vencimento do lançamento; sem vencimento, pelo faturamento.
     */
    private function pagas(Request $request, ?array $periodo): array
    {
        $q = DB::table('franquia_contas_receber as cr')
            ->leftJoin('envios as e', 'e.id', '=', 'cr.envio_id')
            ->leftJoin('vagas as v', 'v.id', '=', 'e.vaga_id')
            ->where('cr.franquia_id', $this->franquiaId)
            ->where('cr.status', 'pago');

        if ($ids = $this->ids($request, 'empresa_ids')) {
            $q->where(fn($w) => $w->whereIn('cr.empresa_id', $ids)->orWhereIn('v.empresa_id', $ids));
        }
        $this->aplicarPeriodo($q, DB::raw('COALESCE(cr.data_vencimento, cr.data_faturamento, cr.created_at)'), $periodo);

        return $q->orderByDesc(DB::raw('COALESCE(cr.data_vencimento, cr.data_faturamento, cr.created_at)'))
            ->get([
                'cr.id', 'cr.candidato_nome', 'cr.vaga_nome', 'cr.empresa_nome', 'cr.franchise_nome',
                'cr.valor_bruto', 'cr.comissao_perc', 'cr.valor_liquido', 'cr.data_vencimento', 'cr.data_faturamento',
            ])
            ->map(fn($r) => [
                'id'         => $r->id,
                'candidato'  => $r->candidato_nome ?: 'Não informado',
                'vaga'       => $r->vaga_nome ?: '—',
                'empresa'    => $r->empresa_nome ?: '—',
                'unidade'    => $r->franchise_nome,
                'faturado'   => (float) $r->valor_bruto,
                'perc'       => $r->comissao_perc !== null ? (float) $r->comissao_perc : null,
                'comissao'   => (float) $r->valor_liquido,
                'vencimento' => $r->data_vencimento ? Carbon::parse($r->data_vencimento)->toDateString() : null,
                'faturado_em'=> $r->data_faturamento ? Carbon::parse($r->data_faturamento)->toDateString() : null,
            ])
            ->values()
            ->all();
    }

    /** Premium: produção por unidade nas vagas dela, inclusive a própria. */
    private function producaoNasMinhasVagas(Request $request, ?array $periodo, Collection $colocacoes): array
    {
        $q = $this->base($request)->where('v.franquia_id', $this->franquiaId);
        $this->aplicarPeriodo($q, 'e.created_at', $periodo);
        $vinculos = $q->groupBy('e.franquia_id')
            ->selectRaw('e.franquia_id as id, COUNT(*) as n')
            ->pluck('n', 'id');

        $minhasVagas = $colocacoes->where('dona_id', $this->franquiaId)
            ->groupBy(fn($c) => (int) $c['produtora_id']);

        $ids = $vinculos->keys()->map(fn($id) => (int) $id)->merge($minhasVagas->keys())->unique();
        $franquias = Franquia::whereIn('id', $ids->filter())->get(['id', 'nome', 'tipo'])->keyBy('id');

        return $ids->map(function ($id) use ($vinculos, $minhasVagas, $franquias) {
            $grupo    = $minhasVagas[$id] ?? collect();
            $nVinc    = (int) ($vinculos[$id ?: ''] ?? $vinculos[$id] ?? 0);
            $fechadas = $grupo->where('estimado', false)->count();
            return [
                'id'         => $id ?: null,
                'nome'       => $id ? ($franquias[$id]->nome ?? '—') : 'Feed / Sem unidade',
                'tipo'       => $id ? ($franquias[$id]->tipo ?? null) : null,
                'eu'         => $id === $this->franquiaId,
                'vinculos'   => $nVinc,
                'fechadas'   => $fechadas,
                'aguardando' => $grupo->where('estimado', true)->count(),
                'conversao'  => $nVinc ? round($fechadas / $nVinc * 100, 1) : null,
                'faturado'   => $this->centavos($grupo, 'faturado'),
                'comissao'   => $this->centavos($grupo, 'minha_comissao'),
            ];
        })
            ->sortBy([['eu', 'desc'], ['comissao', 'desc'], ['vinculos', 'desc']])
            ->values()
            ->all();
    }

    /**
     * Premium: as empresas sob responsabilidade dela. Start: as empresas em que
     * ela produziu, com a unidade responsável.
     */
    private function empresas(Request $request, ?array $periodo, Collection $colocacoes): array
    {
        $q = $this->base($request);
        $this->aplicarPeriodo($q, 'e.created_at', $periodo);
        $vinculos = $q->groupBy('v.empresa_id')->selectRaw('v.empresa_id, COUNT(*) as n')->pluck('n', 'empresa_id');

        $porEmpresa = $colocacoes->groupBy('empresa_id');

        // Última contratação de todos os tempos, dentro do escopo da unidade
        $ultima = $this->base($request)
            ->whereRaw(self::FECHADAS)
            ->groupBy('v.empresa_id')
            ->selectRaw('v.empresa_id, MAX(COALESCE(e.data_admissao, DATE(e.updated_at))) as ultima')
            ->pluck('ultima', 'empresa_id');

        $empresaIds = $this->ids($request, 'empresa_ids');
        $lista = DB::table('empresas as emp')
            ->leftJoin('franquias as fr', 'fr.id', '=', 'emp.franquia_id')
            ->whereNull('emp.deleted_at')
            ->when($this->premium,
                fn($w) => $w->where('emp.franquia_id', $this->franquiaId),
                fn($w) => $w->whereIn('emp.id', $vinculos->keys()->merge($porEmpresa->keys())->unique()))
            ->when($empresaIds, fn($w) => $w->whereIn('emp.id', $empresaIds))
            ->get(['emp.id', 'emp.razao_social', 'emp.nome_fantasia', 'emp.active', 'fr.nome as unidade']);

        $vagas = $this->premium
            ? tap(Vaga::whereIn('empresa_id', $lista->pluck('id')), fn($v) => $this->aplicarPeriodo($v, 'created_at', $periodo))
                ->groupBy('empresa_id')->selectRaw('empresa_id, COUNT(*) as n')->pluck('n', 'empresa_id')
            : collect();

        $hoje = now()->startOfDay();

        return $lista->map(function ($e) use ($vinculos, $porEmpresa, $ultima, $vagas, $hoje) {
            $grupo    = $porEmpresa[$e->id] ?? collect();
            $nVinc    = (int) ($vinculos[$e->id] ?? 0);
            $fechadas = $grupo->where('estimado', false)->count();
            $data     = $ultima[$e->id] ?? null;
            $dias     = $data ? (int) Carbon::parse($data)->startOfDay()->diffInDays($hoje) : null;

            return [
                'id'                 => $e->id,
                'nome'               => $e->nome_fantasia ?: $e->razao_social,
                'ativa'              => (bool) $e->active,
                'unidade'            => $e->unidade,
                'vagas'              => $this->premium ? (int) ($vagas[$e->id] ?? 0) : null,
                'vinculos'           => $nVinc,
                'fechadas'           => $fechadas,
                'aguardando'         => $grupo->where('estimado', true)->count(),
                'conversao'          => $nVinc ? round($fechadas / $nVinc * 100, 1) : null,
                'realizado'          => $this->centavos($grupo->where('estimado', false), 'faturado'),
                'estimado'           => $this->centavos($grupo->where('estimado', true), 'faturado'),
                'comissao'           => $this->centavos($grupo, 'minha_comissao'),
                'ultima_contratacao' => $data ? Carbon::parse($data)->toDateString() : null,
                'situacao'           => match (true) {
                    $data === null                => 'nunca_fechou',
                    $dias > self::DIAS_SEM_FECHAR => 'sem_fechar',
                    default                       => 'ativa',
                },
                'meses_sem_fechar'   => $dias !== null && $dias > self::DIAS_SEM_FECHAR ? intdiv($dias, 30) : null,
            ];
        })
            ->sortBy([['fechadas', 'desc'], ['vinculos', 'desc'], ['nome', 'asc']])
            ->values()
            ->all();
    }

    /** Funil dos vínculos criados no período (mesmas etapas do admin). */
    private function funil(Request $request, ?array $periodo): array
    {
        $r = $this->resumo($request, $periodo);

        $vagas = null;
        if ($this->premium) {
            $q = Vaga::where('franquia_id', $this->franquiaId)
                ->when($this->ids($request, 'empresa_ids'), fn($w, $ids) => $w->whereIn('empresa_id', $ids));
            $this->aplicarPeriodo($q, 'created_at', $periodo);
            $vagas = $q->count();
        }

        return [
            'vagas'             => $vagas,
            'vagas_com_vinculo' => $r['vagas_com_vinculo'],
            'vinculos'          => $r['vinculos'],
            'com_retorno'       => $r['com_retorno'],
            'aguardando'        => $r['vinculos'] - $r['com_retorno'],
            'reprovados'        => $r['reprovados'],
            'desistiu'          => $r['desistiu'],
            'fechadas'          => $r['fechadas'],
            'reposicoes'        => $r['reposicoes'],
        ];
    }

    /** Por mês do ano: vínculos, fechadas e minha comissão (própria x terceiros). */
    private function evolucao(Request $request, int $ano): array
    {
        $doAno = [[Carbon::create($ano)->startOfYear(), Carbon::create($ano)->endOfYear()]];

        $vinculos = $this->base($request)
            ->whereYear('e.created_at', $ano)
            ->groupByRaw('MONTH(e.created_at)')
            ->selectRaw('MONTH(e.created_at) as mes, COUNT(*) as n')
            ->pluck('n', 'mes');

        $colocacoes = $this->comMinhaComissao(app(ValoresColocacoes::class)->calcular(
            $this->contratacoesDe($this->base($request), $doAno), $this->dataAprovacao, $this->dataReferencia))
            ->groupBy(fn($c) => $c['referencia']->month);

        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $doMes = $colocacoes[$m] ?? collect();
            $parte = fn(string $origem, bool $estimado) => $this->centavos(
                $doMes->where('origem', $origem)->where('estimado', $estimado), 'minha_comissao');

            $meses[] = [
                'mes'                 => $m,
                'vinculos'            => (int) ($vinculos[$m] ?? 0),
                'fechadas'            => $doMes->where('estimado', false)->count(),
                'fechadas_estimado'   => $doMes->where('estimado', true)->count(),
                'propria'             => $parte('propria', false),
                'propria_estimado'    => $parte('propria', true),
                'terceiros'           => $parte('terceiros', false),
                'terceiros_estimado'  => $parte('terceiros', true),
            ];
        }

        return ['ano' => $ano, 'meses' => $meses];
    }

    private function atencao(Request $request, array $empresas, Collection $colocacoes): array
    {
        $valores = app(ValoresColocacoes::class);

        // Em garantia: admitidos cujo prazo de reposição ainda não acabou —
        // é o que ainda pode virar reposição. Vale hoje, não o período filtrado.
        $hoje = now()->toDateString();
        $em7  = now()->addDays(7)->toDateString();
        $garantia = $this->comMinhaComissao($valores->calcular(
            $this->comAprovacao($this->base($request))->where('e.status', 'aprovado')
                ->whereNotNull('e.data_admissao')
                ->where('e.data_admissao', '>=', now()->subYear()->toDateString()),
            $this->dataAprovacao,
            $this->dataReferencia,
        ))->filter(fn($c) => $c['garantia_ate'] && $c['garantia_ate'] >= $hoje);

        $semVinculo = null;
        if ($this->premium) {
            $q = Vaga::where('franquia_id', $this->franquiaId)->where('status', 'publicada')->whereDoesntHave('envios')
                ->when($this->ids($request, 'empresa_ids'), fn($w, $ids) => $w->whereIn('empresa_id', $ids));
            $semVinculo = [
                'total'    => (clone $q)->count(),
                'empresas' => (clone $q)->distinct()->count('empresa_id'),
            ];
        }

        $semFechar = collect($empresas)->where('situacao', 'sem_fechar');

        return [
            'garantia' => [
                'total'         => $garantia->count(),
                'comissao'      => $this->centavos($garantia, 'minha_comissao'),
                'vencem_7_dias' => $garantia->filter(fn($c) => $c['garantia_ate'] <= $em7)->count(),
            ],
            'empresas_sem_fechar' => [
                'dias'  => self::DIAS_SEM_FECHAR,
                'total' => $semFechar->count(),
                'nomes' => $semFechar->pluck('nome')->take(5)->values()->all(),
            ],
            'vagas_sem_vinculo' => $semVinculo,
            'incompletas'       => $colocacoes->where('completa', false)->count(),
        ];
    }

    /**
     * Opções dos filtros, de todos os tempos (não mudam com o período): só as
     * empresas e unidades que de fato aparecem no escopo da unidade.
     */
    private function opcoes(): array
    {
        $x = $this->franquiaId;

        $escopo = DB::table('envios as e')
            ->join('vagas as v', 'v.id', '=', 'e.vaga_id')
            ->whereNull('v.deleted_at');
        $this->premium
            ? $escopo->where(fn($w) => $w->where('e.franquia_id', $x)->orWhere('v.franquia_id', $x))
            : $escopo->where('e.franquia_id', $x);

        $empresaIds = (clone $escopo)->distinct()->pluck('v.empresa_id');
        if ($this->premium) {
            $empresaIds = $empresaIds->merge(DB::table('empresas')->where('franquia_id', $x)->whereNull('deleted_at')->pluck('id'));
        }

        // Premium: quem produziu nas vagas dela (ela inclusa). Start: dona da vaga.
        $unidadeIds = $this->premium
            ? (clone $escopo)->where('v.franquia_id', $x)->whereNotNull('e.franquia_id')->distinct()->pluck('e.franquia_id')->push($x)
            : (clone $escopo)->whereNotNull('v.franquia_id')->distinct()->pluck('v.franquia_id');

        return [
            'empresas' => DB::table('empresas')->whereIn('id', $empresaIds->unique())->whereNull('deleted_at')
                ->orderBy('razao_social')
                ->get(['id', 'razao_social', 'nome_fantasia'])
                ->map(fn($e) => ['value' => $e->id, 'label' => $e->nome_fantasia ?: $e->razao_social])
                ->values()->all(),
            'unidades' => Franquia::whereIn('id', $unidadeIds->unique())->orderBy('nome')
                ->get(['id', 'nome', 'tipo'])
                ->map(fn($f) => ['value' => $f->id, 'label' => $f->nome, 'tipo' => $f->tipo])
                ->values()->all(),
        ];
    }
}
