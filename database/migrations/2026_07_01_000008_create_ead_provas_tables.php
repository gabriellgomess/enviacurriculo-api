<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ead_provas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_id')->constrained('ead_cursos')->cascadeOnDelete();
            $table->string('titulo');
            $table->integer('nota_minima')->default(70);
            $table->timestamps();
        });

        Schema::create('ead_prova_questoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prova_id')->constrained('ead_provas')->cascadeOnDelete();
            $table->text('pergunta');
            $table->text('opcao_a');
            $table->text('opcao_b');
            $table->text('opcao_c');
            $table->text('opcao_d');
            $table->char('resposta_correta', 1)->default('a'); // a, b, c, d
            $table->integer('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ead_prova_questoes');
        Schema::dropIfExists('ead_provas');
    }
};
