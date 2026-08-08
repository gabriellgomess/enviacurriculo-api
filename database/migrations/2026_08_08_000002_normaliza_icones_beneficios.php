<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converte os ícones do catálogo de benefícios de emoji para nome do lucide.
 *
 * O seeder original gravou emojis (🏥, 🦷, 🚌…), mas o formulário do admin
 * oferece nomes de ícone (`Heart`, `Utensils`, `Car`, `GraduationCap`, `Gift`).
 * Como nenhum emoji casava com as opções, nada era exibido no cadastro.
 */
return new class extends Migration
{
    /** Emoji do seeder => ícone do lucide. */
    private const POR_EMOJI = [
        '🏥' => 'Heart',          '🦷' => 'Heart',          '🛡️' => 'Heart',
        '🏋️' => 'Heart',
        '🛒' => 'Utensils',       '🍽️' => 'Utensils',       '🏢' => 'Utensils',
        '🚌' => 'Car',            '⛽' => 'Car',            '🅿️' => 'Car',
        '🎓' => 'GraduationCap',  '📚' => 'GraduationCap',  '📖' => 'GraduationCap',
        '🏠' => 'Gift',           '⏰' => 'Gift',           '💰' => 'Gift',
        '🎂' => 'Gift',           '💼' => 'Gift',
    ];

    /** Reserva pela categoria, para itens cadastrados à mão. */
    private const POR_CATEGORIA = [
        'saude'           => 'Heart',
        'alimentacao'     => 'Utensils',
        'transporte'      => 'Car',
        'educacao'        => 'GraduationCap',
        'desenvolvimento' => 'GraduationCap',
        'outros'          => 'Gift',
    ];

    private const VALIDOS = ['Heart', 'Utensils', 'Car', 'GraduationCap', 'Gift'];

    public function up(): void
    {
        if (!Schema::hasTable('beneficios_catalogo')) return;

        foreach (DB::table('beneficios_catalogo')->get() as $b) {
            $atual = trim((string) $b->icone);

            // Já está no padrão novo: não mexe
            if (in_array($atual, self::VALIDOS, true)) continue;

            $novo = self::POR_EMOJI[$atual]
                ?? self::POR_CATEGORIA[$b->categoria]
                ?? 'Gift';

            DB::table('beneficios_catalogo')->where('id', $b->id)->update(['icone' => $novo]);
        }
    }

    public function down(): void
    {
        // Sem volta: o emoji original não é recuperável a partir do nome.
    }
};
