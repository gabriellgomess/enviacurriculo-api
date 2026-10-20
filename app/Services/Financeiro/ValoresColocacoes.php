<?php

namespace App\Services\Financeiro;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calcula os valores das colocações (vínculos aprovados ou em reposição) de
 * uma consulta de envios já filtrada — sem gravar nada. É o que alimenta os
 * indicadores em dinheiro do Desempenho da Rede enquanto o faturamento ainda
 * não é emitido pelo sistema.
 *
 * A consulta recebida precisa ter os aliases "e" (envios), "v" (vagas) e
 * "f" (franquia que produziu), como a base do AdminDesempenhoRedeController.
 * $dataAprovacao é a expressão SQL da data da aprovação nessa consulta, e
 * $dataReferencia a do mês em que a colocação conta (a admissão; para os
 * aprovados ainda sem admissão, a data do estimado).
 *
 * Taxa: a da vaga; sem ela, a taxa cadastrada na empresa para o nível da vaga.
 * Colocação sem salário ou sem taxa fica marcada como incompleta e com valor
 * zero — a tela avisa quantas ficaram de fora.
 */
class ValoresColocacoes
{
    public function __construct(private readonly CalculoColocacao $calculo) {}

    /** @return Collection<int, array> uma linha por colocação */
    public function calcular($envios, string $dataAprovacao = 'e.updated_at', string $dataReferencia = 'e.data_admissao'): Collection
    {
        $configs = Percentuais::configsDoBanco();
        $porTipo = [];

        $linhas = (clone $envios)
            ->whereRaw("e.status IN ('aprovado','reposicao')")
            ->leftJoin('empresa_taxas_servico as ts', function ($j) {
                $j->on('ts.empresa_id', '=', 'v.empresa_id')->on('ts.nivel_vaga_id', '=', 'v.nivel_vaga_id');
            })
            ->leftJoin('empresas as emp_val', 'emp_val.id', '=', 'v.empresa_id')
            ->get([
                'e.id', 'e.status', 'e.origem', 'e.created_at', 'e.data_admissao', 'e.salario_aprovado',
                'e.franquia_id as produtora_id', 'f.tipo as tipo_produtora',
                'v.franquia_id as dona_id', 'v.empresa_id', 'v.taxa_servico', 'ts.percentual as taxa_empresa',
                'emp_val.reposicao_dias',
                DB::raw("{$dataAprovacao} as aprovado_em"),
                DB::raw("{$dataReferencia} as referencia"),
            ])
            // Mais de uma taxa cadastrada para o mesmo nível não pode duplicar a colocação
            ->unique('id');

        return $linhas->map(function ($l) use ($configs, &$porTipo) {
            $salario = (float) $l->salario_aprovado;
            $taxa    = $l->taxa_servico !== null ? (float) $l->taxa_servico
                     : ($l->taxa_empresa !== null ? (float) $l->taxa_empresa : null);
            $completa = $salario > 0 && $taxa !== null && $taxa > 0;

            $tipo = $l->tipo_produtora;
            $p = $porTipo[$tipo ?? '-'] ??= Percentuais::paraTipo($tipo, $configs);
            $v = $completa
                ? $this->calculo->calcular($salario, $taxa, $p, $l->produtora_id, $l->dona_id)
                : null;

            $garantiaAte = null;
            if ($l->data_admissao && (int) $l->reposicao_dias > 0) {
                $garantiaAte = Carbon::parse($l->data_admissao)->addDays((int) $l->reposicao_dias)->toDateString();
            }

            return [
                'id'               => $l->id,
                'status'           => $l->status,
                'criado_em'        => Carbon::parse($l->created_at),
                'aprovado_em'      => Carbon::parse($l->aprovado_em),
                'referencia'       => $l->referencia ? Carbon::parse($l->referencia) : null,
                // Aprovado ainda sem data de admissão: valor estimado
                'estimado'         => $l->data_admissao === null,
                'produtora_id'     => $l->produtora_id,
                'dona_id'          => $l->dona_id,
                'empresa_id'       => $l->empresa_id,
                'grupo'            => $tipo ?: ($l->origem === 'plataforma' ? 'feed' : 'sem_unidade'),
                'completa'         => $completa,
                'faturado'         => $v['faturado'] ?? 0.0,
                // Comissão bruta das unidades: o que está em jogo numa reposição
                'comissoes'        => $v ? $v['comissao_produtora']['bruta'] + $v['comissao_dona']['bruta'] : 0.0,
                // Cada lado separado (líquida = após royalties e marketing): é o
                // que o Desempenho da Franquia mostra como "minha comissão"
                'comissao_produtora'      => $v['comissao_produtora']['liquida'] ?? 0.0,
                'comissao_produtora_perc' => $v['comissao_produtora']['percentual'] ?? null,
                'comissao_dona'           => $v ? $v['comissao_dona']['liquida'] : 0.0,
                'comissao_dona_perc'      => $v && $v['comissao_dona']['devida'] ? $v['comissao_dona']['percentual'] : null,
                'garantia_ate'     => $garantiaAte,
            ];
        })->values();
    }

    /**
     * Preenche o valor_atual das metas em moeda fornecidas, calculando o
     * faturamento realizado (admissão) + estimado (sem admissão) da franquia
     * entre a data_inicio e data_fim de cada meta — exatamente o mesmo cálculo
     * usado no Desempenho da Rede.
     *
     * @param iterable $metas Coleção ou array de MetaFranquia
     * @return iterable
     */
    public function aplicarValorAtualMetas(iterable $metas): iterable
    {
        $lista = $metas instanceof Collection ? $metas : collect($metas);
        $moedaMetas = $lista->filter(fn($m) => !$m->tipoMeta || $m->tipoMeta->unidade === 'moeda');

        if ($moedaMetas->isEmpty()) {
            return $metas;
        }

        $franquiaIds = $moedaMetas->pluck('franquia_id')->filter()->unique()->map(fn($id) => (int) $id)->values()->all();
        if (empty($franquiaIds)) {
            return $metas;
        }

        $dataAprovacao = Schema::hasTable('envio_status_historico')
            ? 'COALESCE(ap.aprovado_em, e.updated_at)'
            : 'e.updated_at';

        $estimadoDesde  = Carbon::parse(config('financeiro.estimado_desde', '2026-09-01'))->toDateString();
        $hoje           = now()->toDateString();
        $dataReferencia = "COALESCE(e.data_admissao, CASE WHEN e.status = 'aprovado' "
            . "AND {$dataAprovacao} >= '{$estimadoDesde}' THEN DATE '{$hoje}' END)";

        $q = DB::table('envios as e')
            ->join('vagas as v', 'v.id', '=', 'e.vaga_id')
            ->leftJoin('franquias as f', 'f.id', '=', 'e.franquia_id')
            ->whereNull('v.deleted_at')
            ->whereIn('e.franquia_id', $franquiaIds)
            ->whereRaw("e.status IN ('aprovado','reposicao')")
            ->whereRaw("{$dataReferencia} IS NOT NULL");

        if ($dataAprovacao !== 'e.updated_at') {
            $ap = DB::table('envio_status_historico')
                ->where('status_novo', 'aprovado')
                ->groupBy('envio_id')
                ->selectRaw('envio_id, MIN(ocorrido_em) as aprovado_em');
            $q->leftJoinSub($ap, 'ap', 'ap.envio_id', '=', 'e.id');
        }

        $colocacoes  = $this->calcular($q, $dataAprovacao, $dataReferencia);
        $porFranquia = $colocacoes->groupBy(fn($c) => (int) $c['produtora_id']);

        foreach ($moedaMetas as $meta) {
            $ini = $meta->data_inicio ? Carbon::parse($meta->data_inicio)->format('Y-m-d') : null;
            $fim = $meta->data_fim ? Carbon::parse($meta->data_fim)->format('Y-m-d') : null;

            $itens = $porFranquia->get((int) $meta->franquia_id) ?? collect();

            $total = $itens->filter(function ($c) use ($ini, $fim) {
                if (!$c['referencia']) {
                    return false;
                }
                $ref = $c['referencia']->format('Y-m-d');
                if ($ini !== null && $ref < $ini) {
                    return false;
                }
                if ($fim !== null && $ref > $fim) {
                    return false;
                }
                return true;
            })->sum('faturado');

            $meta->valor_atual = round($total, 2);
        }

        return $metas;
    }

    /**
     * Calcula o faturamento total (realizado + estimado) de uma franquia específica
     * em um período de referência [início, fim].
     */
    public function calcularFaturamentoFranquia(int $franquiaId, ?string $dataInicio = null, ?string $dataFim = null): float
    {
        $metaMock = new \App\Models\MetaFranquia([
            'franquia_id' => $franquiaId,
            'data_inicio' => $dataInicio,
            'data_fim'    => $dataFim,
        ]);
        $this->aplicarValorAtualMetas(collect([$metaMock]));
        return (float) ($metaMock->valor_atual ?? 0.0);
    }
}
