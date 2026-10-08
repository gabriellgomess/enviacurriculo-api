<?php

namespace App\Services\Financeiro;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcula os valores das colocações (vínculos aprovados ou em reposição) de
 * uma consulta de envios já filtrada — sem gravar nada. É o que alimenta os
 * indicadores em dinheiro do Desempenho da Rede enquanto o faturamento ainda
 * não é emitido pelo sistema.
 *
 * A consulta recebida precisa ter os aliases "e" (envios), "v" (vagas) e
 * "f" (franquia que produziu), como a base do AdminDesempenhoRedeController.
 * $dataAprovacao é a expressão SQL da data da aprovação nessa consulta.
 *
 * Taxa: a da vaga; sem ela, a taxa cadastrada na empresa para o nível da vaga.
 * Colocação sem salário ou sem taxa fica marcada como incompleta e com valor
 * zero — a tela avisa quantas ficaram de fora.
 */
class ValoresColocacoes
{
    public function __construct(private readonly CalculoColocacao $calculo) {}

    /** @return Collection<int, array> uma linha por colocação */
    public function calcular($envios, string $dataAprovacao = 'e.updated_at'): Collection
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
                'produtora_id'     => $l->produtora_id,
                'dona_id'          => $l->dona_id,
                'empresa_id'       => $l->empresa_id,
                'grupo'            => $tipo ?: ($l->origem === 'plataforma' ? 'feed' : 'sem_unidade'),
                'completa'         => $completa,
                'faturado'         => $v['faturado'] ?? 0.0,
                // Comissão bruta das unidades: o que está em jogo numa reposição
                'comissoes'        => $v ? $v['comissao_produtora']['bruta'] + $v['comissao_dona']['bruta'] : 0.0,
                'garantia_ate'     => $garantiaAte,
            ];
        })->values();
    }
}
