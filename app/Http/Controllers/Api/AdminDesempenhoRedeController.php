<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Franquia;
use App\Models\Vaga;
use App\Services\Financeiro\ValoresColocacoes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desempenho da Rede (Admin) — indicadores operacionais da rede inteira.
 *
 * Definições (combinadas com o cliente):
 *  - Vínculo: um envio (candidato ligado a uma vaga), contado pela data em
 *    que foi criado.
 *  - Fechada: vínculo que chegou a Aprovado. Reposição também conta, porque
 *    houve contratação. Conta no período do VÍNCULO, para a conversão
 *    (fechadas ÷ vínculos) ser sempre sobre o mesmo grupo.
 *  - Envio: vínculo encaminhado por uma franquia. Sem franquia, o vínculo é
 *    do feed (candidatura espontânea, envios.origem = 'plataforma') ou "sem
 *    unidade": migrados do sistema antigo sem consultor associado a uma
 *    unidade, ou vinculados pelo Admin.
 *  - Unidade: a franquia que PRODUZIU o vínculo (envios.franquia_id). É ela
 *    que os filtros Unidades/Tipo e a divisão Premium x Start consideram.
 *
 * Valores em dinheiro (faturamento, meta, realizado) entram quando o motor
 * de cálculo financeiro existir; esta versão traz só o que o banco já registra.
 */
class AdminDesempenhoRedeController extends Controller
{
    private const FECHADAS    = "e.status IN ('aprovado','reposicao')";
    private const PENDENTES   = "e.status IN ('enviado','visualizado','pendente')";
    private const EM_PROCESSO = "e.status IN ('em_processo','em_entrevista')";
    // Já tem decisão (aprovado, reprovado ou desistiu). O funil não usa
    // "visualizado" nem "em processo": em produção visualizado_em nunca é
    // preenchido e nenhum vínculo passou por em_processo (dump de 06/10/2026).
    private const COM_RETORNO = "e.status IN ('aprovado','reposicao','reprovado','desistiu')";
    // Grupo do vínculo: tipo da unidade produtora, ou feed / sem unidade
    private const GRUPO = "CASE WHEN f.tipo IS NOT NULL THEN f.tipo WHEN e.origem = 'plataforma' THEN 'feed' ELSE 'sem_unidade' END";
    private const GRUPOS = ['premium', 'start', 'feed', 'sem_unidade'];

    /** Filtro "Origem do vínculo" → valores de envios.origem. */
    private const ORIGENS = [
        'franquia' => 'franquia',   // vinculado no sistema novo (franquia ou Admin)
        'migracao' => 'migracao',   // trazido do sistema antigo
        'feed'     => 'plataforma', // candidatura feita pelo próprio candidato
    ];

    /**
     * Data da aprovação de cada contratação, para contar "o que foi aprovado
     * no mês" (métrica do cliente). Vem do histórico de status; sem linha no
     * histórico, a última alteração do vínculo. Definida em index().
     */
    private string $dataAprovacao = 'e.updated_at';

    /** Empresa sem contratação há mais que isto entra em "Precisa de atenção". */
    private const DIAS_SEM_FECHAR = 90;

    // GET /admin/indicadores/desempenho-rede
    public function index(Request $request)
    {
        $request->validate([
            'de'              => 'nullable|date',
            'ate'             => 'nullable|date',
            'meses'           => 'nullable|array',
            'meses.*'         => 'date_format:Y-m',
            'anos'            => 'nullable|array',
            'anos.*'          => 'integer|min:2000|max:2100',
            'ano_evolucao'    => 'nullable|integer|min:2000|max:2100',
            'franquia_ids'    => 'nullable|array',
            'franquia_ids.*'  => 'integer',
            'empresa_ids'     => 'nullable|array',
            'empresa_ids.*'   => 'integer',
            'tipo'            => 'nullable|in:premium,start',
            'origens'         => 'nullable|array',
            'origens.*'       => 'in:' . implode(',', array_keys(self::ORIGENS)),
            'dona_ids'        => 'nullable|array',
            'dona_ids.*'      => 'integer',
            'nivel_ids'       => 'nullable|array',
            'nivel_ids.*'     => 'integer',
        ]);

        $intervalos = $this->intervalos($request);
        $anterior   = $this->intervaloAnterior($intervalos);

        // Sem a tabela de histórico (migration ainda não aplicada), a tela
        // segue funcionando com a última alteração como data da aprovação
        $this->dataAprovacao = Schema::hasTable('envio_status_historico')
            ? 'COALESCE(ap.aprovado_em, e.updated_at)'
            : 'e.updated_at';

        $resumo        = $this->resumo($request, $intervalos);
        // O funil acompanha os vínculos do período (coorte); os cards contam
        // as contratações aprovadas no período
        $funilCoorte   = $resumo;
        $resumo        = $this->comContratacoes($resumo, $request, $intervalos);
        $rankingUnid   = $this->rankingUnidades($request, $intervalos, $anterior);
        $rankingEmp    = $this->rankingEmpresas($request, $intervalos);
        $vagas         = $this->vagas($request, $intervalos);
        $anoEvolucao   = $this->anoEvolucao($request, $intervalos);

        // Valores em dinheiro (motor de cálculo das colocações) — enriquecem
        // os rankings e a evolução e montam os cards financeiros
        $fin         = $this->financeiro($request, $intervalos, $anterior, $anoEvolucao);
        $rankingUnid = $this->comValoresUnidades($rankingUnid, $fin);
        $rankingEmp  = array_map(fn($e) => $e + ['realizado' => $fin['por_empresa'][$e['id']] ?? 0.0], $rankingEmp);

        return response()->json(['data' => [
            'rede' => [
                'unidades' => Franquia::where('active', true)->count(),
                'empresas' => Empresa::where('active', true)->count(),
            ],
            'resumo'           => $resumo,
            'vinculos_janelas' => $this->janelas($request),
            'situacoes'        => $this->situacoes($request, $intervalos),
            'funil'            => [
                // Vagas que receberam vínculo no período: base do "vínculos por vaga"
                'vagas_com_vinculo' => $funilCoorte['vagas_com_vinculo'],
                'vagas_criadas'     => $vagas['criadas'],
                'vinculos'          => $funilCoorte['vinculos'],
                'com_retorno'       => $funilCoorte['com_retorno'],
                'aguardando'        => $funilCoorte['vinculos'] - $funilCoorte['com_retorno'],
                'reprovados'        => $funilCoorte['reprovados'],
                'desistiu'          => $funilCoorte['desistiu'],
                'fechadas'          => $funilCoorte['fechadas'],
                'reposicoes'        => $funilCoorte['reposicoes'],
            ],
            // Período que a coluna "vs" do ranking de unidades compara
            'comparacao'       => $anterior ? [
                'de'  => $anterior[0][0]->toDateString(),
                'ate' => $anterior[0][1]->toDateString(),
            ] : null,
            'ranking_unidades' => $rankingUnid,
            'ranking_empresas' => $rankingEmp,
            'evolucao'         => $this->evolucao($request, $anoEvolucao, $fin['evolucao']),
            'financeiro'       => $fin['cards'],
            'anos_disponiveis' => $this->anosDisponiveis(),
            'atencao'          => $this->atencao($rankingUnid, $rankingEmp, $vagas, $resumo),
        ]]);
    }

    /* ─── Período ────────────────────────────────────────────────────── */

    /**
     * Intervalos [início, fim] do filtro de período, ou null para "tudo".
     * "Meses" e "Anos" podem ser não contíguos (jan + mar, 2024 + 2026),
     * por isso uma lista.
     */
    private function intervalos(Request $request): ?array
    {
        $anos = array_filter(array_map('intval', (array) $request->input('anos', [])));
        if ($anos) {
            return collect($anos)->unique()->sort()->map(fn($a) => [
                Carbon::create($a)->startOfYear(),
                Carbon::create($a)->endOfYear(),
            ])->values()->all();
        }

        $meses = array_filter((array) $request->input('meses', []));
        if ($meses) {
            return collect($meses)->unique()->sort()->map(function ($m) {
                $inicio = Carbon::createFromFormat('Y-m-d', "{$m}-01")->startOfDay();
                return [$inicio, $inicio->copy()->endOfMonth()];
            })->values()->all();
        }

        if ($request->filled('de') || $request->filled('ate')) {
            $de  = $request->filled('de') ? Carbon::parse($request->de)->startOfDay() : Carbon::create(2000);
            $ate = $request->filled('ate') ? Carbon::parse($request->ate)->endOfDay() : now()->endOfDay();
            return [[$de, $ate]];
        }

        return null;
    }

    /**
     * Período de comparação da coluna "vs" do ranking (só para um intervalo único).
     *
     * Mês e ano voltam um mês / um ano; os demais voltam o próprio tamanho.
     * Se o período ainda está em andamento, compara só o mesmo trecho: em
     * 06/10, outubro (1–6) contra 1–6 de setembro, e não contra setembro
     * inteiro — senão todo mundo aparece em queda no começo do mês.
     */
    private function intervaloAnterior(?array $intervalos): ?array
    {
        if (!$intervalos || count($intervalos) !== 1) {
            return null;
        }
        [$de, $ate] = $intervalos[0];

        $emAndamento = $ate->isFuture();
        $fim = $emAndamento ? now()->endOfDay() : $ate;

        $mesInteiro = $de->day === 1 && $ate->isSameDay($de->copy()->endOfMonth());
        $anoInteiro = $de->dayOfYear === 1 && $ate->isSameDay($de->copy()->endOfYear());

        if ($mesInteiro || $anoInteiro) {
            $voltar = fn(Carbon $d) => $mesInteiro ? $d->copy()->subMonthNoOverflow() : $d->copy()->subYearNoOverflow();
            $inicio = $voltar($de);
            $limite = $mesInteiro ? $inicio->copy()->endOfMonth() : $inicio->copy()->endOfYear();
            $final  = $emAndamento ? $voltar($fim)->endOfDay()->min($limite) : $limite;
            return [[$inicio, $final]];
        }

        // diffInDays devolve fração (fim do dia às 23:59:59): arredonda para baixo
        $dias  = (int) floor($de->diffInDays($ate)) + 1;
        $final = $fim->copy()->subDays($dias)->endOfDay()->min($de->copy()->subSecond());

        return [[$de->copy()->subDays($dias), $final]];
    }

    private function aplicarPeriodo($query, $coluna, ?array $intervalos): void
    {
        if ($intervalos === null) {
            return;
        }
        $query->where(function ($w) use ($coluna, $intervalos) {
            foreach ($intervalos as [$de, $ate]) {
                $w->orWhereBetween($coluna, [$de, $ate]);
            }
        });
    }

    /* ─── Base ───────────────────────────────────────────────────────── */

    private function ids(Request $request, string $campo): array
    {
        return array_values(array_filter(array_map('intval', (array) $request->input($campo, []))));
    }

    /** Vínculos com os filtros de empresa e de unidade produtora aplicados. */
    private function baseEnvios(Request $request)
    {
        $query = DB::table('envios as e')
            ->join('vagas as v', 'v.id', '=', 'e.vaga_id')
            ->leftJoin('franquias as f', 'f.id', '=', 'e.franquia_id')
            ->whereNull('v.deleted_at');

        if ($ids = $this->ids($request, 'empresa_ids')) {
            $query->whereIn('v.empresa_id', $ids);
        }
        if ($ids = $this->ids($request, 'franquia_ids')) {
            $query->whereIn('e.franquia_id', $ids);
        }
        if ($request->filled('tipo')) {
            $query->where('f.tipo', $request->tipo);
        }
        if ($origens = (array) $request->input('origens', [])) {
            $query->whereIn('e.origem', array_map(fn($o) => self::ORIGENS[$o], $origens));
        }
        $this->filtrosDaVaga($query, $request, 'v.');

        return $query;
    }

    /** Junta a data da aprovação (primeira linha "aprovado" do histórico) como ap.aprovado_em. */
    private function comAprovacao($query)
    {
        if ($this->dataAprovacao === 'e.updated_at') {
            return $query;
        }
        $aprovacoes = DB::table('envio_status_historico')
            ->where('status_novo', 'aprovado')
            ->groupBy('envio_id')
            ->selectRaw('envio_id, MIN(ocorrido_em) as aprovado_em');

        return $query->leftJoinSub($aprovacoes, 'ap', 'ap.envio_id', '=', 'e.id');
    }

    /** Contratações (aprovado ou reposição) APROVADAS no período, com os filtros. */
    private function baseContratacoes(Request $request, ?array $periodo)
    {
        $q = $this->comAprovacao($this->baseEnvios($request))->whereRaw(self::FECHADAS);
        $this->aplicarPeriodo($q, DB::raw($this->dataAprovacao), $periodo);
        return $q;
    }

    /**
     * Troca fechadas, reposições e conversão do resumo pelas contratações
     * aprovadas no período. Conversão = aprovados no período ÷ vínculos do
     * período, como o cliente mede.
     */
    private function comContratacoes(array $resumo, Request $request, ?array $intervalos): array
    {
        $r = $this->baseContratacoes($request, $intervalos)
            ->selectRaw('COUNT(*) as fechadas')
            ->selectRaw("SUM(e.status = 'reposicao') as reposicoes")
            ->first();

        $fechadas = (int) $r->fechadas;
        return array_merge($resumo, [
            'fechadas'   => $fechadas,
            'reposicoes' => (int) $r->reposicoes,
            'conversao'  => $resumo['vinculos'] ? round($fechadas / $resumo['vinculos'] * 100, 1) : null,
        ]);
    }

    /**
     * Filtros que são da vaga, não do vínculo: unidade dona e nível. Valem
     * tanto para os vínculos quanto para as contagens de vagas.
     */
    private function filtrosDaVaga($query, Request $request, string $prefixo = ''): void
    {
        if ($ids = $this->ids($request, 'dona_ids')) {
            $query->whereIn($prefixo . 'franquia_id', $ids);
        }
        if ($ids = $this->ids($request, 'nivel_ids')) {
            $query->whereIn($prefixo . 'nivel_vaga_id', $ids);
        }
    }

    /**
     * Algum filtro além de período e empresa (quem encaminhou, tipo, origem,
     * unidade dona, nível)? Com eles, a lista de empresas se limita às que
     * tiveram vínculo filtrado — senão viria cheia de linhas zeradas.
     */
    private function filtraVinculos(Request $request): bool
    {
        return (bool) $this->ids($request, 'franquia_ids')
            || $request->filled('tipo')
            || (bool) $request->input('origens')
            || (bool) $this->ids($request, 'dona_ids')
            || (bool) $this->ids($request, 'nivel_ids');
    }

    /* ─── Seções ─────────────────────────────────────────────────────── */

    private function resumo(Request $request, ?array $intervalos): array
    {
        $q = $this->baseEnvios($request);
        $this->aplicarPeriodo($q, 'e.created_at', $intervalos);

        $r = $q->selectRaw('COUNT(*) as vinculos')
            ->selectRaw('SUM(e.franquia_id IS NOT NULL) as envios')
            ->selectRaw("SUM(e.franquia_id IS NULL AND e.origem = 'plataforma') as feed")
            ->selectRaw("SUM(e.franquia_id IS NULL AND (e.origem IS NULL OR e.origem <> 'plataforma')) as sem_unidade")
            ->selectRaw('SUM(' . self::FECHADAS . ') as fechadas')
            ->selectRaw("SUM(e.status = 'reposicao') as reposicoes")
            ->selectRaw('SUM(' . self::COM_RETORNO . ') as com_retorno')
            ->selectRaw("SUM(e.status = 'reprovado') as reprovados")
            ->selectRaw("SUM(e.status = 'desistiu') as desistiu")
            ->selectRaw('COUNT(DISTINCT e.vaga_id) as vagas_com_vinculo')
            ->first();

        $vinculos = (int) $r->vinculos;
        $fechadas = (int) $r->fechadas;

        return [
            'vinculos'     => $vinculos,
            'envios'       => (int) $r->envios,
            'feed'         => (int) $r->feed,
            'sem_unidade'  => (int) $r->sem_unidade,
            'fechadas'     => $fechadas,
            'reposicoes'   => (int) $r->reposicoes,
            'com_retorno'  => (int) $r->com_retorno,
            'reprovados'   => (int) $r->reprovados,
            'desistiu'     => (int) $r->desistiu,
            'vagas_com_vinculo' => (int) $r->vagas_com_vinculo,
            'conversao'    => $vinculos ? round($fechadas / $vinculos * 100, 1) : null,
        ];
    }

    /** Vínculos de hoje, da semana e do mês corrente — independem do filtro de período. */
    private function janelas(Request $request): array
    {
        $r = $this->baseEnvios($request)
            ->where('e.created_at', '>=', now()->startOfMonth()->min(now()->startOfWeek()))
            ->selectRaw('SUM(e.created_at >= ?) as hoje', [now()->startOfDay()])
            ->selectRaw('SUM(e.created_at >= ?) as semana', [now()->startOfWeek()])
            ->selectRaw('SUM(e.created_at >= ?) as mes', [now()->startOfMonth()])
            ->first();

        return [
            'hoje'   => (int) $r->hoje,
            'semana' => (int) $r->semana,
            'mes'    => (int) $r->mes,
        ];
    }

    /** Situações por grupo: tipo da unidade produtora, feed ou sem unidade. */
    private function situacoes(Request $request, ?array $intervalos): array
    {
        $q = $this->baseEnvios($request);
        $this->aplicarPeriodo($q, 'e.created_at', $intervalos);

        $linhas = $q->selectRaw(self::GRUPO . ' as tipo')
            ->selectRaw('SUM(' . self::PENDENTES . ') as pendentes')
            ->selectRaw('SUM(' . self::EM_PROCESSO . ') as em_processo')
            ->selectRaw("SUM(e.status = 'desistiu') as desistiu")
            ->selectRaw("SUM(e.status = 'reprovado') as reprovados")
            // Aprovados = fechadas (reposição inclusa): os cinco somam os vínculos do grupo
            ->selectRaw('SUM(' . self::FECHADAS . ') as aprovados')
            ->groupByRaw(self::GRUPO)
            ->get()
            ->keyBy('tipo');

        $vazio = ['pendentes' => 0, 'em_processo' => 0, 'desistiu' => 0, 'reprovados' => 0, 'aprovados' => 0];

        return collect(self::GRUPOS)->mapWithKeys(fn($t) => [
            $t => isset($linhas[$t])
                ? array_map('intval', array_intersect_key((array) $linhas[$t], $vazio))
                : $vazio,
        ])->all();
    }

    /** Vagas criadas no período e vagas publicadas sem nenhum vínculo. */
    private function vagas(Request $request, ?array $intervalos): array
    {
        // Vaga não tem "quem encaminhou" nem origem: aqui valem só os
        // filtros da própria vaga (empresa, unidade dona e nível).
        $filtrar = function ($q) use ($request) {
            if ($ids = $this->ids($request, 'empresa_ids')) {
                $q->whereIn('empresa_id', $ids);
            }
            $this->filtrosDaVaga($q, $request);
            return $q;
        };

        $criadas = $filtrar(Vaga::query());
        $this->aplicarPeriodo($criadas, 'created_at', $intervalos);

        $semVinculo = $filtrar(Vaga::where('status', 'publicada')->whereDoesntHave('envios'));

        return [
            'criadas'                => $criadas->count(),
            'sem_vinculo'            => (clone $semVinculo)->count(),
            'sem_vinculo_empresas'   => (clone $semVinculo)->distinct()->count('empresa_id'),
            'empresas_com_vaga_aberta' => $filtrar(Vaga::where('status', 'publicada'))
                ->distinct()->pluck('empresa_id')->all(),
        ];
    }

    private function rankingUnidades(Request $request, ?array $intervalos, ?array $anterior): array
    {
        // Vínculos pela data do vínculo; fechadas pela data da aprovação
        $porUnidade = function (?array $periodo) use ($request) {
            $q = $this->baseEnvios($request)->whereNotNull('e.franquia_id');
            $this->aplicarPeriodo($q, 'e.created_at', $periodo);
            $vinculos = $q->groupBy('e.franquia_id')->selectRaw('e.franquia_id, COUNT(*) as n')->pluck('n', 'franquia_id');

            $fechadas = $this->baseContratacoes($request, $periodo)->whereNotNull('e.franquia_id')
                ->groupBy('e.franquia_id')->selectRaw('e.franquia_id, COUNT(*) as n')->pluck('n', 'franquia_id');

            return $vinculos->keys()->merge($fechadas->keys())->unique()->mapWithKeys(fn($id) => [$id => (object) [
                'vinculos' => (int) ($vinculos[$id] ?? 0),
                'fechadas' => (int) ($fechadas[$id] ?? 0),
            ]]);
        };

        $atual = $porUnidade($intervalos);
        $antes = $anterior ? $porUnidade($anterior) : null;

        $franquias = Franquia::where('active', true)
            ->when($this->ids($request, 'franquia_ids'), fn($q, $ids) => $q->whereIn('id', $ids))
            ->when($request->filled('tipo'), fn($q) => $q->where('tipo', $request->tipo))
            ->get(['id', 'nome', 'tipo', 'cidade', 'estado', 'cidade_empresa', 'estado_empresa']);

        return $franquias->map(function ($f) use ($atual, $antes) {
            $vinculos = (int) ($atual[$f->id]->vinculos ?? 0);
            $fechadas = (int) ($atual[$f->id]->fechadas ?? 0);

            return [
                'id'                => $f->id,
                'nome'              => $f->nome,
                'tipo'              => $f->tipo,
                'cidade'            => $f->cidade ?: $f->cidade_empresa,
                'estado'            => $f->estado ?: $f->estado_empresa,
                'vinculos'          => $vinculos,
                'fechadas'          => $fechadas,
                'conversao'         => $vinculos ? round($fechadas / $vinculos * 100, 1) : null,
                'fechadas_anterior' => $antes ? (int) ($antes[$f->id]->fechadas ?? 0) : null,
            ];
        })
            ->sortBy([['fechadas', 'desc'], ['vinculos', 'desc'], ['nome', 'asc']])
            ->values()
            ->all();
    }

    private function rankingEmpresas(Request $request, ?array $intervalos): array
    {
        // Vínculos pela data do vínculo; fechadas pela data da aprovação
        $q = $this->baseEnvios($request);
        $this->aplicarPeriodo($q, 'e.created_at', $intervalos);
        $vinc = $q->groupBy('v.empresa_id')->selectRaw('v.empresa_id, COUNT(*) as n')->pluck('n', 'empresa_id');
        $fech = $this->baseContratacoes($request, $intervalos)
            ->groupBy('v.empresa_id')->selectRaw('v.empresa_id, COUNT(*) as n')->pluck('n', 'empresa_id');
        $porEmpresa = $vinc->keys()->merge($fech->keys())->unique()->mapWithKeys(fn($id) => [$id => (object) [
            'vinculos' => (int) ($vinc[$id] ?? 0),
            'fechadas' => (int) ($fech[$id] ?? 0),
        ]]);

        $vagasQ = Vaga::query()
            ->when($this->ids($request, 'empresa_ids'), fn($q, $ids) => $q->whereIn('empresa_id', $ids));
        $this->filtrosDaVaga($vagasQ, $request);
        $this->aplicarPeriodo($vagasQ, 'created_at', $intervalos);
        $vagasPorEmpresa = $vagasQ->groupBy('empresa_id')
            ->selectRaw('empresa_id, COUNT(*) as total')
            ->pluck('total', 'empresa_id');

        // Última contratação de todos os tempos (quem produziu não importa)
        $ultima = DB::table('envios as e')
            ->join('vagas as v', 'v.id', '=', 'e.vaga_id')
            ->whereRaw(self::FECHADAS)
            ->when($this->ids($request, 'empresa_ids'), fn($q, $ids) => $q->whereIn('v.empresa_id', $ids))
            ->groupBy('v.empresa_id')
            ->selectRaw('v.empresa_id, MAX(COALESCE(e.data_admissao, DATE(e.updated_at))) as ultima')
            ->pluck('ultima', 'empresa_id');

        // Ativas, mais as inativas que tiveram vínculo no período: sem isso a
        // tabela escondia empresas que somam nos totais (em produção, 164
        // inativas com 2.273 vínculos e 135 fechadas).
        $empresas = DB::table('empresas as emp')
            ->leftJoin('franquias as fr', 'fr.id', '=', 'emp.franquia_id')
            ->whereNull('emp.deleted_at')
            ->where(fn($w) => $w->where('emp.active', true)->orWhereIn('emp.id', $porEmpresa->keys()))
            ->when($this->ids($request, 'empresa_ids'), fn($q, $ids) => $q->whereIn('emp.id', $ids))
            // Filtrando por unidade, só interessam as empresas em que ela produziu
            ->when($this->filtraVinculos($request), fn($q) => $q->whereIn('emp.id', $porEmpresa->keys()))
            ->get(['emp.id', 'emp.razao_social', 'emp.nome_fantasia', 'emp.active', 'fr.nome as unidade']);

        $hoje = now()->startOfDay();

        return $empresas->map(function ($e) use ($porEmpresa, $vagasPorEmpresa, $ultima, $hoje) {
            $vinculos = (int) ($porEmpresa[$e->id]->vinculos ?? 0);
            $fechadas = (int) ($porEmpresa[$e->id]->fechadas ?? 0);
            $data     = $ultima[$e->id] ?? null;
            $dias     = $data ? (int) Carbon::parse($data)->startOfDay()->diffInDays($hoje) : null;

            return [
                'id'                 => $e->id,
                'nome'               => $e->nome_fantasia ?: $e->razao_social,
                'ativa'              => (bool) $e->active,
                'unidade'            => $e->unidade,
                'vagas'              => (int) ($vagasPorEmpresa[$e->id] ?? 0),
                'vinculos'           => $vinculos,
                'fechadas'           => $fechadas,
                'conversao'          => $vinculos ? round($fechadas / $vinculos * 100, 1) : null,
                'ultima_contratacao' => $data ? Carbon::parse($data)->toDateString() : null,
                'situacao'           => match (true) {
                    $data === null                     => 'nunca_fechou',
                    $dias > self::DIAS_SEM_FECHAR      => 'sem_fechar',
                    default                            => 'ativa',
                },
                'meses_sem_fechar'   => $dias !== null && $dias > self::DIAS_SEM_FECHAR ? intdiv($dias, 30) : null,
            ];
        })
            ->sortBy([['fechadas', 'desc'], ['vinculos', 'desc'], ['nome', 'asc']])
            ->values()
            ->all();
    }

    /* ─── Valores em dinheiro ────────────────────────────────────────── */

    /**
     * Indicadores financeiros calculados pelo motor (ValoresColocacoes), sem
     * depender de fatura emitida: o "faturamento" aqui é o valor gerado pelas
     * colocações (salário da admissão × taxa da vaga), na mesma data de
     * referência do resto da tela — a do vínculo.
     */
    private function financeiro(Request $request, ?array $intervalos, ?array $anterior, int $anoEvolucao): array
    {
        $valores = app(ValoresColocacoes::class);
        $doPeriodo = fn(?array $periodo) => $valores->calcular($this->baseContratacoes($request, $periodo), $this->dataAprovacao);

        $atual = $doPeriodo($intervalos);
        $antes = $anterior ? $doPeriodo($anterior) : null;

        // Em garantia: aprovados cujo prazo de reposição ainda não acabou —
        // é o que ainda pode virar reposição. Vale hoje, não o período filtrado.
        $hoje = now()->toDateString();
        $em7  = now()->addDays(7)->toDateString();
        $garantia = $valores->calcular(
            $this->comAprovacao($this->baseEnvios($request))->where('e.status', 'aprovado')
                ->whereNotNull('e.data_admissao')
                ->where('e.data_admissao', '>=', now()->subYear()->toDateString()),
            $this->dataAprovacao,
        )->filter(fn($c) => $c['garantia_ate'] && $c['garantia_ate'] >= $hoje);

        $porMes = $valores->calcular($this->baseContratacoes($request, [[
            Carbon::create($anoEvolucao)->startOfYear(), Carbon::create($anoEvolucao)->endOfYear(),
        ]]), $this->dataAprovacao)
            ->groupBy(fn($c) => $c['aprovado_em']->month)
            ->map(fn($doMes) => $doMes->groupBy('grupo')->map(fn($g) => $g->sum('faturado'))->all())
            ->all();

        $porTipo = $atual->groupBy('grupo')->map(fn($g) => round($g->sum('faturado'), 2));
        $metas   = $this->metasEmDinheiro($intervalos);
        $tipoDe  = Franquia::pluck('tipo', 'id');

        return [
            'cards' => [
                'faturamento'          => round($atual->sum('faturado'), 2),
                'faturamento_anterior' => $antes ? round($antes->sum('faturado'), 2) : null,
                'colocacoes'           => $atual->count(),
                // Sem salário ou sem taxa: ficam fora dos valores (a tela avisa)
                'incompletas'          => $atual->where('completa', false)->count(),
                'por_tipo'             => [
                    'premium'     => $porTipo['premium'] ?? 0.0,
                    'start'       => $porTipo['start'] ?? 0.0,
                    'feed'        => $porTipo['feed'] ?? 0.0,
                    'sem_unidade' => $porTipo['sem_unidade'] ?? 0.0,
                ],
                'meta_por_tipo'        => [
                    'premium' => round(collect($metas)->filter(fn($v, $id) => ($tipoDe[$id] ?? null) === 'premium')->sum(), 2),
                    'start'   => round(collect($metas)->filter(fn($v, $id) => ($tipoDe[$id] ?? null) === 'start')->sum(), 2),
                ],
                'reposicoes_comissao'  => round($atual->where('status', 'reposicao')->sum('comissoes'), 2),
                'garantia'             => [
                    'total'      => $garantia->count(),
                    'comissoes'  => round($garantia->sum('comissoes'), 2),
                    'vencem_7_dias' => $garantia->filter(fn($c) => $c['garantia_ate'] <= $em7)->count(),
                ],
            ],
            'por_unidade'          => $atual->groupBy('produtora_id')->map(fn($g) => round($g->sum('faturado'), 2))->all(),
            'por_unidade_anterior' => $antes?->groupBy('produtora_id')->map(fn($g) => round($g->sum('faturado'), 2))->all(),
            'por_empresa'          => $atual->groupBy('empresa_id')->map(fn($g) => round($g->sum('faturado'), 2))->all(),
            'metas'                => $metas,
            'evolucao'             => $porMes,
        ];
    }

    /**
     * Metas em dinheiro (tipos de meta com unidade "moeda", ex.: Faturamento)
     * que tocam o período, somadas por unidade. Sem período, todas.
     *
     * @return array<int, float> [franquia_id => soma das metas]
     */
    private function metasEmDinheiro(?array $intervalos): array
    {
        $q = DB::table('metas_franquias as m')
            ->join('tipos_metas as t', 't.id', '=', 'm.tipo_meta_id')
            ->where('t.unidade', 'moeda')
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

        return $q->groupBy('m.franquia_id')
            ->selectRaw('m.franquia_id, SUM(m.valor_meta) as total')
            ->pluck('total', 'franquia_id')
            ->map(fn($v) => (float) $v)
            ->all();
    }

    /**
     * Acrescenta realizado (faturamento produzido), o do período de comparação
     * e a meta em dinheiro a cada unidade, e reordena por faturamento — como o
     * ranking do mockup ("por faturamento no período").
     */
    private function comValoresUnidades(array $unidades, array $fin): array
    {
        return collect($unidades)->map(function ($u) use ($fin) {
            $realizado = $fin['por_unidade'][$u['id']] ?? 0.0;
            $meta      = $fin['metas'][$u['id']] ?? null;
            return $u + [
                'realizado'          => $realizado,
                'realizado_anterior' => $fin['por_unidade_anterior'] === null ? null : ($fin['por_unidade_anterior'][$u['id']] ?? 0.0),
                'meta'               => $meta,
                'meta_perc'          => $meta ? round($realizado / $meta * 100, 1) : null,
            ];
        })
            ->sortBy([['realizado', 'desc'], ['fechadas', 'desc'], ['vinculos', 'desc'], ['nome', 'asc']])
            ->values()
            ->all();
    }

    /** Anos com vínculo registrado, do primeiro até o corrente (opções dos filtros de ano). */
    private function anosDisponiveis(): array
    {
        $primeiro = DB::table('envios')->min('created_at');
        $inicio = $primeiro ? Carbon::parse($primeiro)->year : now()->year;
        return range($inicio, now()->year);
    }

    /**
     * Vínculos e fechadas por mês de um ano, por tipo. O ano é o escolhido no
     * próprio gráfico; sem escolha, o último ano do período (ou o corrente).
     */
    private function anoEvolucao(Request $request, ?array $intervalos): int
    {
        return $request->filled('ano_evolucao')
            ? (int) $request->ano_evolucao
            : ($intervalos ? end($intervalos)[1]->year : now()->year);
    }

    /** @param array $faturamento [mes][grupo] => valor faturado das colocações */
    private function evolucao(Request $request, int $ano, array $faturamento): array
    {

        $linhas = $this->baseEnvios($request)
            ->whereYear('e.created_at', $ano)
            ->selectRaw('MONTH(e.created_at) as mes')
            ->selectRaw(self::GRUPO . ' as tipo')
            ->selectRaw('COUNT(*) as vinculos')
            ->groupByRaw('MONTH(e.created_at), ' . self::GRUPO)
            ->get();

        // Fechadas pelo mês em que foram aprovadas
        $fechadas = $this->baseContratacoes($request, [[Carbon::create($ano)->startOfYear(), Carbon::create($ano)->endOfYear()]])
            ->selectRaw("MONTH({$this->dataAprovacao}) as mes")
            ->selectRaw(self::GRUPO . ' as tipo')
            ->selectRaw('COUNT(*) as fechadas')
            ->groupByRaw("MONTH({$this->dataAprovacao}), " . self::GRUPO)
            ->get();

        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $doMes = $linhas->where('mes', $m);
            $fechMes = $fechadas->where('mes', $m);
            $porTipo = fn($t) => [
                'vinculos'    => (int) $doMes->where('tipo', $t)->sum('vinculos'),
                'fechadas'    => (int) $fechMes->where('tipo', $t)->sum('fechadas'),
                'faturamento' => round($faturamento[$m][$t] ?? 0, 2),
            ];
            $meses[] = [
                'mes'         => $m,
                ...collect(self::GRUPOS)->mapWithKeys(fn($t) => [$t => $porTipo($t)])->all(),
                'vinculos'    => (int) $doMes->sum('vinculos'),
                'fechadas'    => (int) $fechMes->sum('fechadas'),
                'faturamento' => round(array_sum($faturamento[$m] ?? []), 2),
            ];
        }

        return ['ano' => $ano, 'meses' => $meses];
    }

    private function atencao(array $unidades, array $empresas, array $vagas, array $resumo): array
    {
        $semFechada = collect($unidades)->where('fechadas', 0)->pluck('nome')->values();

        $comVagaAberta = array_flip($vagas['empresas_com_vaga_aberta']);
        $semFechar = collect($empresas)->where('situacao', 'sem_fechar');

        return [
            'unidades_sem_fechadas' => [
                'total' => $semFechada->count(),
                'nomes' => $semFechada->all(),
            ],
            'empresas_sem_fechar' => [
                'dias'            => self::DIAS_SEM_FECHAR,
                'total'           => $semFechar->count(),
                'com_vaga_aberta' => $semFechar->filter(fn($e) => isset($comVagaAberta[$e['id']]))->count(),
            ],
            'vagas_sem_vinculo' => [
                'total'    => $vagas['sem_vinculo'],
                'empresas' => $vagas['sem_vinculo_empresas'],
            ],
            'reposicoes' => $resumo['reposicoes'],
        ];
    }
}
