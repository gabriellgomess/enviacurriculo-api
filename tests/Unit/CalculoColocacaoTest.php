<?php

namespace Tests\Unit;

use App\Services\Financeiro\CalculoColocacao;
use App\Services\Financeiro\Percentuais;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Confere o cálculo contra a planilha Simulação.xlsx do cliente (6 colocações).
 *
 * Os valores esperados são os da planilha, com uma ressalva: nas colunas de
 * comissão da dona e marketing da dona, algumas células foram gravadas com uma
 * casa decimal (ex.: 4,2). Usamos o valor exato (4,23), que é o que a coluna
 * seguinte da própria planilha confirma (423,30 − 42,33 − 4,23 = 376,74).
 */
class CalculoColocacaoTest extends TestCase
{
    // Unidades fictícias: produtora e dona diferentes, como nas linhas da planilha
    private const PRODUTORA = 1;
    private const DONA      = 2;

    public static function linhasDaPlanilha(): array
    {
        // salário, taxa %, dona?, faturado, imposto, base,
        // [bruta, royalties, marketing, líquida] produtora, [..] dona, lucro
        return [
            'Belmec (Guilherme)' => [4250, 60, true, 2550.00, 433.50, 2116.50,
                [846.60, 84.66, 8.47, 753.47], [423.30, 42.33, 4.23, 376.74], 986.29],
            'Belmec (Laercio)'   => [3510, 60, true, 2106.00, 358.02, 1747.98,
                [699.19, 69.92, 6.99, 622.28], [349.60, 34.96, 3.50, 311.14], 814.56],
            'Blue (Paola)'       => [2230.80, 60, true, 1338.48, 227.54, 1110.94,
                [444.38, 44.44, 4.44, 395.49], [222.19, 22.22, 2.22, 197.75], 517.70],
            'Conexsul (Andreza)' => [6000, 85, true, 5100.00, 867.00, 4233.00,
                [1693.20, 169.32, 16.93, 1506.95], [846.60, 84.66, 8.47, 753.47], 1972.58],
            'Conf. Juliana (Interno)' => [2000, 100, false, 2000.00, 340.00, 1660.00,
                [664.00, 66.40, 6.64, 590.96], [0, 0, 0, 0], 1069.04],
            'EF Tecidos (Dinah)' => [5000, 100, true, 5000.00, 850.00, 4150.00,
                [1660.00, 166.00, 16.60, 1477.40], [830.00, 83.00, 8.30, 738.70], 1933.90],
        ];
    }

    #[DataProvider('linhasDaPlanilha')]
    public function test_bate_com_a_planilha(
        float $salario, float $taxa, bool $temDona,
        float $faturado, float $imposto, float $base,
        array $produtora, array $dona, float $lucro,
    ): void {
        $r = (new CalculoColocacao)->calcular(
            $salario, $taxa, Percentuais::padrao(), self::PRODUTORA, $temDona ? self::DONA : null,
        );

        $this->assertSame($faturado, $r['faturado']);
        $this->assertSame($imposto, $r['imposto']);
        $this->assertSame($base, $r['base_comissao']);
        $this->assertSame($produtora, array_values(array_intersect_key($r['comissao_produtora'], array_flip(['bruta', 'royalties', 'marketing', 'liquida']))));
        $this->assertEquals($dona, array_values(array_intersect_key($r['comissao_dona'], array_flip(['bruta', 'royalties', 'marketing', 'liquida']))));
        $this->assertSame($lucro, $r['lucro_rede']);
    }

    public function test_lucro_total_das_seis_colocacoes(): void
    {
        $calc  = new CalculoColocacao;
        $total = 0.0;
        foreach (self::linhasDaPlanilha() as [$salario, $taxa, $temDona]) {
            $total += $calc->calcular($salario, $taxa, Percentuais::padrao(), self::PRODUTORA, $temDona ? self::DONA : null)['lucro_rede'];
        }

        $this->assertSame(7294.07, CalculoColocacao::centavos($total));
    }

    public function test_quem_produziu_e_dona_recebe_so_a_comissao_de_produtora(): void
    {
        // Regra do cliente: é um OU outro, nunca 40% + 20%
        $r = (new CalculoColocacao)->calcular(4250, 60, Percentuais::padrao(), self::DONA, self::DONA);

        $this->assertSame(846.60, $r['comissao_produtora']['bruta']);
        $this->assertSame(0.0, $r['comissao_dona']['bruta']);
        $this->assertFalse($r['comissao_dona']['devida']);
        // A parte da dona fica com a rede
        $this->assertSame(1363.03, $r['lucro_rede']);
    }

    public function test_percentuais_lidos_do_cadastro(): void
    {
        $configs = [
            'percentual_imposto'  => ['premium' => 17, 'start' => 17],
            'percentual_comissao' => ['premium' => 40, 'start' => 40, 's_start' => 20],
            'tx_royalties'        => ['start' => 10],
            'tx_marketing'        => ['start' => 1],
        ];
        $p = Percentuais::paraTipo('start', $configs);

        $this->assertSame([17.0, 40.0, 20.0, 10.0, 1.0], [$p->imposto, $p->comissaoProdutora, $p->comissaoDona, $p->royalties, $p->marketing]);
    }

    public function test_arredonda_meio_para_cima_mesmo_com_erro_de_ponto_flutuante(): void
    {
        $this->assertSame(4.23, CalculoColocacao::centavos(4.225));
        $this->assertSame(8.47, CalculoColocacao::centavos(8.466));
        $this->assertSame(0.0, CalculoColocacao::centavos(0.0));
    }
}
