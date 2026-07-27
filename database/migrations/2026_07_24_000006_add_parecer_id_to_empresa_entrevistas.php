<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula a entrevista (tela Entrevistas da Empresa) ao parecer da franquia,
 * mantendo os dois lados sincronizados: a entrevista informada no parecer
 * (dados.entrevista_empresa_data / dados.entrevista_empresa_local) aparece
 * para a empresa, e alterações feitas pela empresa voltam para o parecer.
 *
 * Entrevistas criadas manualmente pela empresa ficam com parecer_id nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_entrevistas', function (Blueprint $table) {
            $table->foreignId('parecer_id')
                ->nullable()
                ->after('vaga_id')
                ->constrained('candidato_pareceres')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('empresa_entrevistas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parecer_id');
        });
    }
};
