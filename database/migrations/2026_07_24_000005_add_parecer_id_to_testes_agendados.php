<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula o teste agendado (tela Testes da Empresa) ao parecer da franquia,
 * permitindo manter os dois lados sincronizados: o teste informado no parecer
 * (dados.teste_empresa_data / dados.teste_empresa_local) aparece para a
 * empresa, e alterações feitas pela empresa voltam para o parecer.
 *
 * Testes criados manualmente pela empresa ficam com parecer_id nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testes_agendados', function (Blueprint $table) {
            $table->foreignId('parecer_id')
                ->nullable()
                ->after('vaga_id')
                ->constrained('candidato_pareceres')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('testes_agendados', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parecer_id');
        });
    }
};
