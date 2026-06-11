<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Conjunto padrão de 24 grupos de adjetivos DISC (PT-BR).
 * Cada grupo tem 4 palavras, uma por fator (D, I, S, C), em ordem variada.
 * O cliente pode revisar/substituir as palavras conforme o material oficial.
 */
class DiscQuestoesSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('disc_questoes')->exists()) {
            return; // não duplica
        }

        $grupos = [
            // [opcao_a, fator_a, opcao_b, fator_b, opcao_c, fator_c, opcao_d, fator_d]
            ['Determinado', 'D', 'Entusiasmado', 'I', 'Paciente', 'S', 'Detalhista', 'C'],
            ['Competitivo', 'D', 'Comunicativo', 'I', 'Calmo', 'S', 'Preciso', 'C'],
            ['Ousado', 'D', 'Otimista', 'I', 'Leal', 'S', 'Cauteloso', 'C'],
            ['Direto', 'D', 'Persuasivo', 'I', 'Estável', 'S', 'Organizado', 'C'],
            ['Decidido', 'D', 'Sociável', 'I', 'Tranquilo', 'S', 'Analítico', 'C'],
            ['Enérgico', 'D', 'Expressivo', 'I', 'Constante', 'S', 'Disciplinado', 'C'],
            ['Firme', 'D', 'Animado', 'I', 'Cooperativo', 'S', 'Perfeccionista', 'C'],
            ['Independente', 'D', 'Convincente', 'I', 'Bom ouvinte', 'S', 'Criterioso', 'C'],
            ['Corajoso', 'D', 'Inspirador', 'I', 'Prestativo', 'S', 'Sistemático', 'C'],
            ['Objetivo', 'D', 'Extrovertido', 'I', 'Conciliador', 'S', 'Metódico', 'C'],
            ['Assertivo', 'D', 'Carismático', 'I', 'Confiável', 'S', 'Lógico', 'C'],
            ['Vigoroso', 'D', 'Alegre', 'I', 'Sereno', 'S', 'Cuidadoso', 'C'],
            ['Pioneiro', 'D', 'Espontâneo', 'I', 'Ponderado', 'S', 'Exigente', 'C'],
            ['Autoconfiante', 'D', 'Popular', 'I', 'Gentil', 'S', 'Reservado', 'C'],
            ['Audacioso', 'D', 'Encantador', 'I', 'Compreensivo', 'S', 'Rigoroso', 'C'],
            ['Resoluto', 'D', 'Divertido', 'I', 'Moderado', 'S', 'Meticuloso', 'C'],
            ['Dominante', 'D', 'Influente', 'I', 'Persistente', 'S', 'Prudente', 'C'],
            ['Empreendedor', 'D', 'Articulado', 'I', 'Harmonioso', 'S', 'Formal', 'C'],
            ['Vencedor', 'D', 'Caloroso', 'I', 'Equilibrado', 'S', 'Conservador', 'C'],
            ['Rápido', 'D', 'Falante', 'I', 'Acolhedor', 'S', 'Pontual', 'C'],
            ['Exigente comigo', 'D', 'Receptivo', 'I', 'Flexível', 'S', 'Racional', 'C'],
            ['Líder', 'D', 'Motivador', 'I', 'Solidário', 'S', 'Investigativo', 'C'],
            ['Realizador', 'D', 'Vibrante', 'I', 'Dedicado', 'S', 'Planejador', 'C'],
            ['Desafiador', 'D', 'Amigável', 'I', 'Tolerante', 'S', 'Consistente', 'C'],
        ];

        $rows = [];
        foreach ($grupos as $i => [$a, $fa, $b, $fb, $c, $fc, $d, $fd]) {
            $rows[] = [
                'grupo'      => $i + 1,
                'opcao_a'    => $a, 'fator_a' => $fa,
                'opcao_b'    => $b, 'fator_b' => $fb,
                'opcao_c'    => $c, 'fator_c' => $fc,
                'opcao_d'    => $d, 'fator_d' => $fd,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('disc_questoes')->insert($rows);
    }
}
