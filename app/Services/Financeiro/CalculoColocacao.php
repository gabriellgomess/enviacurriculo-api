<?php

namespace App\Services\Financeiro;

/**
 * Cálculo financeiro de UMA colocação (candidato contratado).
 *
 * Regras confirmadas com o cliente (planilha Simulação.xlsx, out/2026):
 *  - Faturado = salário informado na admissão × taxa de serviço da vaga.
 *  - Imposto sobre o faturado; base de comissão = faturado − imposto.
 *  - Quem produziu recebe % da base (40%); a dona da vaga recebe % da base
 *    (20%). É um OU outro: se a mesma unidade produziu e é a dona, recebe só
 *    a comissão de quem produziu. Sem dona, não há comissão de dona.
 *  - Royalties e marketing incidem sobre cada comissão e ficam com a rede.
 *  - Lucro da rede = base − comissões líquidas pagas às unidades.
 *
 * Como na planilha, tudo é calculado com precisão total e só o resultado de
 * cada valor é arredondado para centavos. Arredondar a cada passo erraria por
 * um centavo (ex.: comissão líquida da linha "Blue" da planilha).
 *
 * O mínimo mensal de royalties/marketing (R$ 400 / R$ 50) não entra aqui:
 * ele vale para o período da unidade, não para cada colocação — é tratado
 * no fechamento.
 */
class CalculoColocacao
{
    /**
     * @param float    $salario        salário informado na admissão
     * @param float    $taxaServico    taxa da vaga, em % (ex.: 60 para 60%)
     * @param int|null $produtoraId    unidade que encaminhou o candidato
     * @param int|null $donaId         unidade dona da vaga
     */
    public function calcular(
        float $salario,
        float $taxaServico,
        Percentuais $p,
        ?int $produtoraId,
        ?int $donaId,
    ): array {
        $faturado = $salario * $taxaServico / 100;
        $imposto  = $faturado * $p->imposto / 100;
        $base     = $faturado - $imposto;

        $produtora = $this->comissao($base, $p->comissaoProdutora, $p);

        // Um ou outro: a dona só recebe quando outra unidade produziu
        $donaRecebe = $donaId !== null && $donaId !== $produtoraId;
        $dona = $this->comissao($base, $donaRecebe ? $p->comissaoDona : 0.0, $p);

        $lucro = $base - $produtora['liquida_exata'] - $dona['liquida_exata'];

        return [
            'faturado'            => self::centavos($faturado),
            'imposto'             => self::centavos($imposto),
            'base_comissao'       => self::centavos($base),
            'comissao_produtora'  => $this->publicar($produtora),
            'comissao_dona'       => $this->publicar($dona) + ['devida' => $donaRecebe],
            'lucro_rede'          => self::centavos($lucro),
            'lucro_rede_perc'     => $base > 0 ? round($lucro / $base * 100, 1) : null,
        ];
    }

    private function comissao(float $base, float $percentual, Percentuais $p): array
    {
        $bruta     = $base * $percentual / 100;
        $royalties = $bruta * $p->royalties / 100;
        $marketing = $bruta * $p->marketing / 100;

        return [
            'percentual'    => $percentual,
            'bruta'         => $bruta,
            'royalties'     => $royalties,
            'marketing'     => $marketing,
            'liquida_exata' => $bruta - $royalties - $marketing,
        ];
    }

    private function publicar(array $c): array
    {
        return [
            'percentual' => $c['percentual'],
            'bruta'      => self::centavos($c['bruta']),
            'royalties'  => self::centavos($c['royalties']),
            'marketing'  => self::centavos($c['marketing']),
            'liquida'    => self::centavos($c['liquida_exata']),
        ];
    }

    /**
     * Arredonda para centavos, meio para cima. O empurrão de 1e-9 corrige a
     * representação binária de valores como 4,225 (guardado como 4,22499…),
     * que sem ele cairiam para baixo.
     */
    public static function centavos(float $valor): float
    {
        $empurrao = $valor >= 0 ? 1e-9 : -1e-9;
        return round($valor + $empurrao, 2, PHP_ROUND_HALF_UP);
    }
}
