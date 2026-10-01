<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Envio;
use Illuminate\Http\Request;

/**
 * Regras compartilhadas pelas listagens de vagas do Admin e da Franquia:
 * filtros que aceitam mais de um valor e a contagem de candidatos por situação.
 */
trait ListagemVagas
{
    /**
     * Situações exibidas no card da vaga → status de envios que cada uma agrupa.
     * "Pendentes" junta os três nomes que a mesma etapa tem no banco
     * (ver Envio::statusFranquiaPara).
     */
    private const SITUACOES_CANDIDATOS = [
        'aprovados'   => ['aprovado'],
        'pendentes'   => ['enviado', 'visualizado', 'pendente'],
        'em_processo' => ['em_processo', 'em_entrevista'],
        'reprovados'  => ['reprovado'],
        'desistiu'    => ['desistiu'],
        'reposicao'   => ['reposicao'],
    ];

    /**
     * Aplica um filtro que pode chegar como lista (`campo[]=a&campo[]=b`, vindo
     * dos seletores múltiplos) ou como valor único (chamadas antigas).
     *
     * Lista compara por igualdade: as opções vêm dos valores cadastrados, então
     * "Analista" não deve trazer junto "Analista de RH". Valor único em campo
     * de texto mantém a busca parcial que já existia.
     */
    protected function filtrarPorLista($query, Request $request, string $param, string $coluna, bool $parcialSeUnico = false): void
    {
        $valor = $request->input($param);

        if (is_array($valor)) {
            $lista = array_values(array_filter($valor, fn($v) => is_scalar($v) && $v !== ''));
            if ($lista) {
                $query->whereIn($coluna, $lista);
            }
            return;
        }

        if ($valor === null || $valor === '') {
            return;
        }

        $parcialSeUnico
            ? $query->where($coluna, 'like', '%' . $valor . '%')
            : $query->where($coluna, $valor);
    }

    /**
     * Quantidade de candidatos por situação para um conjunto de vagas, numa
     * única consulta: [vaga_id => ['aprovados' => 8, 'pendentes' => 50, ...]].
     */
    protected function candidatosPorSituacao(array $vagaIds): array
    {
        if (!$vagaIds) {
            return [];
        }

        $grupoDoStatus = [];
        foreach (self::SITUACOES_CANDIDATOS as $grupo => $statuses) {
            foreach ($statuses as $status) {
                $grupoDoStatus[$status] = $grupo;
            }
        }

        $linhas = Envio::whereIn('vaga_id', $vagaIds)
            ->selectRaw('vaga_id, status, COUNT(*) as total')
            ->groupBy('vaga_id', 'status')
            ->get();

        $resultado = [];
        foreach ($linhas as $linha) {
            $grupo = $grupoDoStatus[$linha->status] ?? null;
            if (!$grupo) {
                continue;
            }
            $resultado[$linha->vaga_id] ??= $this->situacoesZeradas();
            $resultado[$linha->vaga_id][$grupo] += (int) $linha->total;
        }

        return $resultado;
    }

    protected function situacoesZeradas(): array
    {
        return array_fill_keys(array_keys(self::SITUACOES_CANDIDATOS), 0);
    }

    /** Valores distintos e não vazios de uma coluna, em ordem alfabética. */
    protected function opcoesDistintas($query, string $coluna): array
    {
        return $query->whereNotNull($coluna)->where($coluna, '!=', '')
            ->distinct()->orderBy($coluna)->pluck($coluna)
            ->map(fn($v) => trim($v))->filter()->unique()->values()->all();
    }
}
