<?php

namespace App\Services\Financeiro;

use App\Models\FinanceiroConfig;

/**
 * Percentuais usados no cálculo de uma colocação, todos em % (17 = 17%).
 *
 * Vêm de Configurações › Financeiro (tabela financeiro_configs), que guarda
 * um valor por categoria e tipo de franquia:
 *  - percentual_imposto, tx_royalties, tx_marketing: por tipo da unidade
 *  - percentual_comissao: o tipo premium/start é a comissão de quem produziu;
 *    o tipo "s_start" é a comissão da dona da vaga (o rótulo antigo
 *    "Sobre Start", que passa a valer para qualquer terceiro).
 */
final class Percentuais
{
    public function __construct(
        public readonly float $imposto,
        public readonly float $comissaoProdutora,
        public readonly float $comissaoDona,
        public readonly float $royalties,
        public readonly float $marketing,
    ) {}

    /** Valores da planilha do cliente, para referência e testes. */
    public static function padrao(): self
    {
        return new self(imposto: 17, comissaoProdutora: 40, comissaoDona: 20, royalties: 10, marketing: 1);
    }

    /**
     * Lê os percentuais cadastrados para o tipo da unidade que produziu.
     * Categoria sem valor cadastrado cai no padrão da planilha.
     *
     * @param array<string, array<string, float>> $configs  [categoria][tipo] => valor
     */
    public static function paraTipo(?string $tipoProdutora, array $configs): self
    {
        $tipo   = $tipoProdutora ?: 'start';
        $padrao = self::padrao();
        $valor  = fn(string $cat, string $t, float $default) => (float) ($configs[$cat][$t] ?? $default);

        return new self(
            imposto:           $valor('percentual_imposto', $tipo, $padrao->imposto),
            comissaoProdutora: $valor('percentual_comissao', $tipo, $padrao->comissaoProdutora),
            comissaoDona:      $valor('percentual_comissao', 's_start', $padrao->comissaoDona),
            royalties:         $valor('tx_royalties', $tipo, $padrao->royalties),
            marketing:         $valor('tx_marketing', $tipo, $padrao->marketing),
        );
    }

    /** Todos os valores cadastrados, num formato pronto para paraTipo(). */
    public static function configsDoBanco(): array
    {
        $configs = [];
        foreach (FinanceiroConfig::all(['categoria', 'tipo_franquia', 'valor']) as $c) {
            $configs[$c->categoria][$c->tipo_franquia] = (float) $c->valor;
        }
        return $configs;
    }
}
