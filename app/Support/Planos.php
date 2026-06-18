<?php

namespace App\Support;

/**
 * Catálogo de planos do painel Empresa (Plataforma).
 *
 * Valores e features espelham a aplicação de referência (send-cv-central,
 * EmpresaMeuPlano). A coluna empresa.plano guarda apenas a chave.
 */
class Planos
{
    public const CATALOGO = [
        'basico' => [
            'chave'        => 'basico',
            'nome'         => 'Plano Básico',
            'preco'        => 49.99,
            'preco_mensal' => 49.99,
            'descricao'    => 'Ideal para quem quer apenas receber currículos',
            'destaque'     => false,
            'recursos'     => [
                'acesso_a_plataforma'       => true,
                'divulgar_vagas_ilimitadas' => false,
                'receber_curriculos'        => true,
            ],
        ],
        'padrao' => [
            'chave'        => 'padrao',
            'nome'         => 'Plano Padrão',
            'preco'        => 199.99,
            'preco_mensal' => 199.99,
            'descricao'    => 'Ideal para clientes com até 80 colaboradores PJ/CLT/terceiros',
            'destaque'     => true,
            'recursos'     => [
                'acesso_a_plataforma'       => true,
                'divulgar_vagas_ilimitadas' => true,
                'receber_curriculos'        => true,
            ],
        ],
        'premium' => [
            'chave'        => 'premium',
            'nome'         => 'Plano Premium',
            'preco'        => 499.99,
            'preco_mensal' => 499.99,
            'descricao'    => 'Ideal para clientes com mais de 80 colaboradores PJ/CLT/terceiros',
            'destaque'     => false,
            'recursos'     => [
                'acesso_a_plataforma'       => true,
                'divulgar_vagas_ilimitadas' => true,
                'receber_curriculos'        => true,
            ],
        ],
    ];

    /** @return array<int,array> lista de planos para o catálogo */
    public static function all(): array
    {
        return array_values(self::CATALOGO);
    }

    public static function find(?string $chave): ?array
    {
        return $chave !== null ? (self::CATALOGO[$chave] ?? null) : null;
    }

    public static function chaves(): array
    {
        return array_keys(self::CATALOGO);
    }

    public static function permitePublicarVagas(?string $chave): bool
    {
        return self::find($chave)['recursos']['divulgar_vagas_ilimitadas'] ?? false;
    }

    public static function permiteReceberFeed(?string $chave): bool
    {
        // Todos os planos recebem currículos/feed.
        return self::find($chave) !== null;
    }
}
