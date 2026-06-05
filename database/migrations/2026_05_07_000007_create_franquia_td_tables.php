<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Onboarding: itens globais (admin cria), progresso por franquia
        Schema::create('franquia_onboarding_itens', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('franquia_onboarding_progresso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('franquia_onboarding_itens')->cascadeOnDelete();
            $table->boolean('concluido')->default(false);
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->unique(['franquia_id', 'item_id']);
        });

        // EAD: cursos e aulas criados pelo admin, progresso por franquia
        Schema::create('ead_cursos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ead_aulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_id')->constrained('ead_cursos')->cascadeOnDelete();
            $table->string('titulo');
            $table->unsignedSmallInteger('duracao_minutos')->default(0);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->index('curso_id');
        });

        Schema::create('ead_progresso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->foreignId('aula_id')->constrained('ead_aulas')->cascadeOnDelete();
            $table->boolean('concluida')->default(false);
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            $table->unique(['franquia_id', 'aula_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ead_progresso');
        Schema::dropIfExists('ead_aulas');
        Schema::dropIfExists('ead_cursos');
        Schema::dropIfExists('franquia_onboarding_progresso');
        Schema::dropIfExists('franquia_onboarding_itens');
    }
};
