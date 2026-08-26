<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 100 cargos mais comuns no mercado de trabalho brasileiro, usados como
 * ponto de partida para a lista de Cargos gerenciada em Admin > Configurações.
 */
class CargoSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('cargos')->exists()) {
            return; // não duplica
        }

        $cargos = [
            'Auxiliar Administrativo', 'Assistente Administrativo', 'Analista Administrativo',
            'Recepcionista', 'Secretária(o)', 'Auxiliar de Escritório', 'Auxiliar de Serviços Gerais',
            'Auxiliar de Limpeza', 'Porteiro', 'Vigilante', 'Segurança', 'Motorista',
            'Motorista Entregador', 'Office Boy / Office Girl', 'Auxiliar de Logística',
            'Analista de Logística', 'Auxiliar de Estoque', 'Conferente de Estoque',
            'Operador de Empilhadeira', 'Auxiliar de Produção', 'Operador de Produção',
            'Supervisor de Produção', 'Auxiliar de Almoxarifado', 'Almoxarife',
            'Auxiliar de Expedição', 'Vendedor', 'Vendedor Externo', 'Vendedor Interno',
            'Consultor de Vendas', 'Representante Comercial', 'Promotor de Vendas', 'Caixa',
            'Operador de Caixa', 'Repositor', 'Repositor de Mercadorias', 'Atendente de Loja',
            'Balconista', 'Auxiliar de Vendas', 'Gerente de Loja', 'Gerente Comercial',
            'Supervisor Comercial', 'Auxiliar de Marketing', 'Analista de Marketing',
            'Assistente de Marketing', 'Analista de Mídias Sociais', 'Auxiliar Financeiro',
            'Analista Financeiro', 'Assistente Financeiro', 'Auxiliar Contábil',
            'Analista Contábil', 'Assistente Contábil', 'Contador', 'Auxiliar Fiscal',
            'Analista Fiscal', 'Analista de Recursos Humanos', 'Auxiliar de Recursos Humanos',
            'Assistente de Recursos Humanos', 'Analista de Departamento Pessoal',
            'Auxiliar de Departamento Pessoal', 'Recrutador', 'Analista de RH',
            'Técnico de Segurança do Trabalho', 'Auxiliar de Cozinha', 'Cozinheiro',
            'Cozinheiro Industrial', 'Ajudante de Cozinha', 'Garçom / Garçonete',
            'Auxiliar de Limpeza Hospitalar', 'Copeira', 'Camareira', 'Recepcionista de Hotel',
            'Auxiliar de Manutenção', 'Técnico de Manutenção', 'Eletricista', 'Encanador',
            'Pedreiro', 'Servente de Obras', 'Pintor', 'Soldador', 'Mecânico',
            'Mecânico de Manutenção', 'Auxiliar Mecânico', 'Técnico em Eletrônica',
            'Técnico em Informática', 'Analista de Suporte Técnico', 'Analista de TI',
            'Desenvolvedor de Sistemas', 'Analista de Sistemas', 'Técnico de Enfermagem',
            'Auxiliar de Enfermagem', 'Enfermeiro', 'Farmacêutico', 'Auxiliar de Farmácia',
            'Balconista de Farmácia', 'Professor', 'Auxiliar de Ensino', 'Auxiliar de Costura',
            'Costureira', 'Auxiliar de Confecção', 'Estagiário',
        ];

        $now = now();

        DB::table('cargos')->insert(
            array_map(fn ($nome) => [
                'nome'       => $nome,
                'created_at' => $now,
                'updated_at' => $now,
            ], $cargos)
        );
    }
}
