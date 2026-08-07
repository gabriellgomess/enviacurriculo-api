<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisão E do plano de migração.
 *
 * Destino dos 3.066 registros de `candidate_follow_up_history` do sistema antigo.
 * São anotações de contato com o candidato ("ofereci a vaga X, não teve interesse"),
 * diferentes de parecer — por isso tabela própria e não `candidato_pareceres`.
 *
 * `criado_por` é nullable porque apenas 37 dos 3.066 registros antigos têm autor
 * identificado; o restante guardava só o nome do consultor em texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidato_followups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->foreignId('franquia_id')->nullable()->constrained('franquias')->nullOnDelete();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->text('anotacao');
            $table->date('data_contato')->nullable();

            $table->timestamps();

            $table->index(['candidato_id', 'created_at']);
            $table->index('franquia_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidato_followups');
    }
};
