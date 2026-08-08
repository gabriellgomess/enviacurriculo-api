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
            ['nome' => 'Plano de Saúde',       'icone' => 'Heart', 'categoria' => 'saude'],
            ['nome' => 'Plano Odontológico',    'icone' => 'Heart', 'categoria' => 'saude'],
            ['nome' => 'Seguro de Vida',        'icone' => 'Heart', 'categoria' => 'saude'],
            ['nome' => 'Gympass / Wellhub',     'icone' => 'Heart', 'categoria' => 'saude'],
            // alimentacao
            ['nome' => 'Vale-Alimentação',      'icone' => 'Utensils', 'categoria' => 'alimentacao'],
            ['nome' => 'Vale-Refeição',         'icone' => 'Utensils', 'categoria' => 'alimentacao'],
            ['nome' => 'Refeitório na Empresa', 'icone' => 'Utensils', 'categoria' => 'alimentacao'],
            // transporte
            ['nome' => 'Vale-Transporte',       'icone' => 'Car', 'categoria' => 'transporte'],
            ['nome' => 'Auxílio Combustível',   'icone' => 'Car', 'categoria' => 'transporte'],
            ['nome' => 'Estacionamento',        'icone' => 'Car', 'categoria' => 'transporte'],
            // educacao
            ['nome' => 'Auxílio Educação',      'icone' => 'GraduationCap', 'categoria' => 'educacao'],
            ['nome' => 'Cursos e Treinamentos', 'icone' => 'GraduationCap', 'categoria' => 'educacao'],
            ['nome' => 'Bolsa de Estudos',      'icone' => 'GraduationCap', 'categoria' => 'educacao'],
            // outros
            ['nome' => 'Home Office',           'icone' => 'Gift', 'categoria' => 'outros'],
            ['nome' => 'Horário Flexível',      'icone' => 'Gift', 'categoria' => 'outros'],
            ['nome' => 'PLR / Bônus',           'icone' => 'Gift', 'categoria' => 'outros'],
            ['nome' => 'Day Off Aniversário',   'icone' => 'Gift', 'categoria' => 'outros'],
            ['nome' => 'Previdência Privada',   'icone' => 'Gift', 'categoria' => 'outros'],
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
