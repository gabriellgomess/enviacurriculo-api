<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controle de quais informações da vaga aparecem para o candidato, por canal.
 *
 * O anúncio na PLATAFORMA usa as colunas já existentes (2026_06_12_000003):
 * ocultar_empresa, ocultar_endereco e exibir_salario (exposto como
 * salario_oculto) — por isso não criamos um ocultar_salario redundante.
 *
 * As colunas _agencia abaixo valem para o anúncio enviado à AGÊNCIA, usadas
 * quando canal='ambos', em que a mesma vaga gera os dois anúncios com
 * configurações de visibilidade independentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->boolean('ocultar_salario_agencia')->default(false)->after('ocultar_endereco');
            $table->boolean('ocultar_empresa_agencia')->default(false)->after('ocultar_salario_agencia');
            $table->boolean('ocultar_endereco_agencia')->default(false)->after('ocultar_empresa_agencia');
        });
    }

    public function down(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->dropColumn([
                'ocultar_salario_agencia',
                'ocultar_empresa_agencia',
                'ocultar_endereco_agencia',
            ]);
        });
    }
};
