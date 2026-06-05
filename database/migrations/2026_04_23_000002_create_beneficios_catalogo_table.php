<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficios_catalogo', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('icone')->nullable();
            $table->enum('categoria', ['saude', 'alimentacao', 'transporte', 'educacao', 'outros'])->default('outros');
            $table->boolean('is_sistema')->default(true);
            $table->timestamps();
        });

        // Benefícios padrão
        $beneficios = [
            // saude
            ['nome' => 'Plano de Saúde',       'icone' => '🏥', 'categoria' => 'saude'],
            ['nome' => 'Plano Odontológico',    'icone' => '🦷', 'categoria' => 'saude'],
            ['nome' => 'Seguro de Vida',        'icone' => '🛡️', 'categoria' => 'saude'],
            ['nome' => 'Gympass / Wellhub',     'icone' => '🏋️', 'categoria' => 'saude'],
            // alimentacao
            ['nome' => 'Vale-Alimentação',      'icone' => '🛒', 'categoria' => 'alimentacao'],
            ['nome' => 'Vale-Refeição',         'icone' => '🍽️', 'categoria' => 'alimentacao'],
            ['nome' => 'Refeitório na Empresa', 'icone' => '🏢', 'categoria' => 'alimentacao'],
            // transporte
            ['nome' => 'Vale-Transporte',       'icone' => '🚌', 'categoria' => 'transporte'],
            ['nome' => 'Auxílio Combustível',   'icone' => '⛽', 'categoria' => 'transporte'],
            ['nome' => 'Estacionamento',        'icone' => '🅿️', 'categoria' => 'transporte'],
            // educacao
            ['nome' => 'Auxílio Educação',      'icone' => '🎓', 'categoria' => 'educacao'],
            ['nome' => 'Cursos e Treinamentos', 'icone' => '📚', 'categoria' => 'educacao'],
            ['nome' => 'Bolsa de Estudos',      'icone' => '📖', 'categoria' => 'educacao'],
            // outros
            ['nome' => 'Home Office',           'icone' => '🏠', 'categoria' => 'outros'],
            ['nome' => 'Horário Flexível',      'icone' => '⏰', 'categoria' => 'outros'],
            ['nome' => 'PLR / Bônus',           'icone' => '💰', 'categoria' => 'outros'],
            ['nome' => 'Day Off Aniversário',   'icone' => '🎂', 'categoria' => 'outros'],
            ['nome' => 'Previdência Privada',   'icone' => '💼', 'categoria' => 'outros'],
        ];

        foreach ($beneficios as $b) {
            \DB::table('beneficios_catalogo')->insert(array_merge($b, [
                'is_sistema' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficios_catalogo');
    }
};
