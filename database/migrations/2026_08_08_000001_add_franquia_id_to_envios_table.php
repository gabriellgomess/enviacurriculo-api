<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registra qual franquia é responsável por cada encaminhamento.
 *
 * Até aqui a visibilidade do histórico era derivada da vaga, o que estava
 * errado: no sistema antigo a vaga era um pool compartilhado (mediana de 37
 * consultores com acesso a cada uma). Quem de fato atendeu está em
 * `candidate_jobs.consultant_id`, e é essa informação que precisa viajar
 * junto com o envio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->foreignId('franquia_id')->nullable()->after('vaga_id')
                  ->constrained('franquias')->nullOnDelete();
            $table->index('franquia_id');
        });
    }

    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropForeign(['franquia_id']);
            $table->dropIndex(['franquia_id']);
            $table->dropColumn('franquia_id');
        });
    }
};
