<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fluxo "quero ser empresa":
 *  - Plataforma/Ambos: assinatura recorrente no Asaas antes de liberar acesso
 *  - Agência: acesso liberado sem cobrança (e sem funcionalidades)
 *  - Todos geram lead no Comercial, identificado pelo produto contratado
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('asaas_customer_id')->nullable()->after('active');
            $table->string('asaas_subscription_id')->nullable()->after('asaas_customer_id');
            $table->decimal('plano_valor', 12, 2)->nullable()->after('asaas_subscription_id');
            $table->string('assinatura_status', 20)->nullable()->after('plano_valor');

            $table->index('asaas_subscription_id');
        });

        Schema::table('franquia_leads', function (Blueprint $table) {
            // Produto do lead de empresa: plataforma | agencia | ambos
            $table->string('produto', 20)->nullable()->after('tipo');
            $table->index('produto');
        });

        Schema::table('franquia_contas_receber', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_id')->nullable()->after('parceiro_id');
            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::table('franquia_contas_receber', function (Blueprint $table) {
            $table->dropIndex(['empresa_id']);
            $table->dropColumn('empresa_id');
        });

        Schema::table('franquia_leads', function (Blueprint $table) {
            $table->dropIndex(['produto']);
            $table->dropColumn('produto');
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->dropIndex(['asaas_subscription_id']);
            $table->dropColumn([
                'asaas_customer_id', 'asaas_subscription_id',
                'plano_valor', 'assinatura_status',
            ]);
        });
    }
};
