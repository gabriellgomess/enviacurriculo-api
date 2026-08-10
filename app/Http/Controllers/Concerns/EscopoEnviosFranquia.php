<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Envio;
use App\Models\Vaga;
use Illuminate\Support\Collection;

/**
 * Escopo único de "quais envios pertencem a esta franquia".
 *
 * A regra é: envio que a franquia registrou (`envios.franquia_id`) OU envio
 * recebido numa vaga de que ela é dona (`vagas.franquia_id`).
 *
 * Ter isso num só lugar importa porque a resposta mudou. Vários controllers
 * partiam apenas das vagas da franquia, o que funcionava enquanto cada franquia
 * era dona de algumas. Depois que toda vaga migrada passou para a Unidade
 * Matriz (decisão 7), esse critério virou conjunto vazio e zerou dashboards,
 * relatórios, mapa e agenda — sem erro nenhum, só números errados.
 *
 * A responsabilidade pelo encaminhamento mora em `envios.franquia_id`, gravado
 * no Passo 6 da migração e nos endpoints de vincular.
 */
trait EscopoEnviosFranquia
{
    /** Vagas de que a franquia é dona. */
    protected function vagasDaFranquia(int $franquiaId): Collection
    {
        return Vaga::where('franquia_id', $franquiaId)->pluck('id');
    }

    /**
     * Aplica o escopo a uma query de Envio (ou a uma relação de envios).
     *
     * @param  \Illuminate\Support\Collection|null  $vagaIds  reaproveita a lista
     *         quando o chamador já a tem em mãos, evitando uma consulta a mais.
     */
    protected function escopoEnviosFranquia($query, int $franquiaId, ?Collection $vagaIds = null)
    {
        $vagaIds ??= $this->vagasDaFranquia($franquiaId);

        return $query->where(function ($q) use ($franquiaId, $vagaIds) {
            $q->where('franquia_id', $franquiaId)
              ->orWhereIn('vaga_id', $vagaIds);
        });
    }

    /** Query de envios já no escopo da franquia. */
    protected function enviosDaFranquia(int $franquiaId, ?Collection $vagaIds = null)
    {
        return $this->escopoEnviosFranquia(Envio::query(), $franquiaId, $vagaIds);
    }

    /**
     * Cláusula para usar dentro de whereHas('envios', ...).
     *
     * Uso: `Candidato::whereHas('envios', $this->filtroEnviosFranquia($id))`
     */
    protected function filtroEnviosFranquia(int $franquiaId, ?Collection $vagaIds = null): \Closure
    {
        $vagaIds ??= $this->vagasDaFranquia($franquiaId);

        return function ($q) use ($franquiaId, $vagaIds) {
            $q->where(function ($sub) use ($franquiaId, $vagaIds) {
                $sub->where('franquia_id', $franquiaId)
                    ->orWhereIn('vaga_id', $vagaIds);
            });
        };
    }
}
