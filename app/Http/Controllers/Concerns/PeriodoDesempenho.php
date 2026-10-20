<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Período e datas de referência dos indicadores de desempenho — o mesmo
 * critério no Desempenho da Rede (admin) e no Desempenho da Franquia, para os
 * números das duas telas baterem.
 *
 * As consultas recebidas precisam ter os aliases "e" (envios) e "v" (vagas).
 *  - Vínculo conta pela data de criação.
 *  - Contratação (aprovado ou reposição) conta pela data de admissão; sem
 *    admissão, é "estimado" no mês corrente (só aprovações a partir de
 *    config('financeiro.estimado_desde')).
 *  - Reposição conta pela data de saída.
 */
trait PeriodoDesempenho
{
    /** Data da aprovação: histórico de status; sem histórico, a última alteração. */
    private string $dataAprovacao = 'e.updated_at';

    /** Data em que a contratação conta (admissão, ou hoje para o estimado). */
    private string $dataReferencia = 'e.data_admissao';

    /** Primeiro dia de aprovação que pode entrar no estimado (Y-m-d). */
    private string $estimadoDesde = '';

    /** Monta $dataAprovacao, $estimadoDesde e $dataReferencia. Chamar antes das consultas. */
    private function prepararDatas(): void
    {
        // Sem a tabela de histórico (migration ainda não aplicada), segue com
        // a última alteração como data da aprovação
        $this->dataAprovacao = Schema::hasTable('envio_status_historico')
            ? 'COALESCE(ap.aprovado_em, e.updated_at)'
            : 'e.updated_at';

        // Datas formatadas pelo Carbon: seguras para ir direto no SQL
        $this->estimadoDesde = Carbon::parse(config('financeiro.estimado_desde', '2026-09-01'))->toDateString();
        $hoje                = now()->toDateString();
        // Estimado só para status Aprovado: reposição sem admissão (o candidato
        // saiu) não é faturamento previsto e fica fora
        $this->dataReferencia = "COALESCE(e.data_admissao, CASE WHEN e.status = 'aprovado' "
            . "AND {$this->dataAprovacao} >= '{$this->estimadoDesde}' THEN DATE '{$hoje}' END)";
    }

    private function ids(Request $request, string $campo): array
    {
        return array_values(array_filter(array_map('intval', (array) $request->input($campo, []))));
    }

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
     * Período de comparação (só para um intervalo único).
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

    /**
     * Contratações (aprovado ou reposição) de uma consulta de envios que contam
     * no período: admissão no período, ou estimadas (sem admissão) quando o
     * período inclui hoje. Separe as duas com e.data_admissao IS NULL.
     */
    private function contratacoesDe($envios, ?array $periodo)
    {
        $q = $this->comAprovacao($envios)
            ->whereRaw("e.status IN ('aprovado','reposicao')")
            // Aprovação antiga sem admissão: fora de qualquer período
            ->whereRaw("{$this->dataReferencia} IS NOT NULL");
        $this->aplicarPeriodo($q, DB::raw($this->dataReferencia), $periodo);
        return $q;
    }

    /**
     * Reposições de uma consulta de envios que ACONTECERAM no período, pela
     * data de saída do candidato. Sem data de saída: a data em que o status
     * virou reposição no histórico; sem histórico, a última alteração.
     */
    private function reposicoesDe($envios, ?array $periodo)
    {
        $q = $this->comAprovacao($envios)->where('e.status', 'reposicao');

        $dataReposicao = 'COALESCE(e.data_saida, e.updated_at)';
        if ($this->dataAprovacao !== 'e.updated_at') {
            $reposicoes = DB::table('envio_status_historico')
                ->where('status_novo', 'reposicao')
                ->groupBy('envio_id')
                ->selectRaw('envio_id, MIN(ocorrido_em) as reposicao_em');
            $q->leftJoinSub($reposicoes, 'rp', 'rp.envio_id', '=', 'e.id');
            $dataReposicao = 'COALESCE(e.data_saida, rp.reposicao_em, e.updated_at)';
        }

        $this->aplicarPeriodo($q, DB::raw("CAST({$dataReposicao} AS DATETIME)"), $periodo);
        return $q;
    }

    /** Anos com vínculo registrado, do primeiro até o corrente (opções dos filtros de ano). */
    private function anosDisponiveis(): array
    {
        $primeiro = DB::table('envios')->min('created_at');
        $inicio = $primeiro ? Carbon::parse($primeiro)->year : now()->year;
        return range($inicio, now()->year);
    }

    /** Ano do gráfico de evolução: o escolhido; sem escolha, o último ano do período (ou o corrente). */
    private function anoEvolucao(Request $request, ?array $intervalos): int
    {
        return $request->filled('ano_evolucao')
            ? (int) $request->ano_evolucao
            : ($intervalos ? end($intervalos)[1]->year : now()->year);
    }
}
