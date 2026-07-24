<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── Dados da assinatura Asaas do parceiro ────────────────────────────
        Schema::table('parceiros', function (Blueprint $table) {
            $table->string('asaas_customer_id')->nullable()->after('active');
            $table->string('asaas_subscription_id')->nullable()->after('asaas_customer_id');
            $table->string('plano', 30)->nullable()->after('asaas_subscription_id');
            $table->decimal('plano_valor', 12, 2)->nullable()->after('plano');
            $table->string('assinatura_status', 20)->nullable()->default('pendente')->after('plano_valor');

            $table->index('asaas_subscription_id');
        });

        // ── Contas a receber: passa a comportar também assinaturas de parceiros ─
        // franquia_id deixa de ser obrigatório (receita de parceiro não tem franquia)
        DB::statement('ALTER TABLE franquia_contas_receber MODIFY franquia_id BIGINT UNSIGNED NULL');

        Schema::table('franquia_contas_receber', function (Blueprint $table) {
            $table->string('origem', 20)->default('franquia')->after('franquia_id');
            $table->unsignedBigInteger('parceiro_id')->nullable()->after('origem');
            $table->string('descricao')->nullable()->after('parceiro_id');
            $table->string('asaas_payment_id')->nullable()->after('descricao');
            $table->string('asaas_subscription_id')->nullable()->after('asaas_payment_id');

            $table->index('origem');
            $table->index('parceiro_id');
            $table->index('asaas_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('franquia_contas_receber', function (Blueprint $table) {
            $table->dropIndex(['origem']);
            $table->dropIndex(['parceiro_id']);
            $table->dropIndex(['asaas_payment_id']);
            $table->dropColumn([
                'origem', 'parceiro_id', 'descricao',
                'asaas_payment_id', 'asaas_subscription_id',
            ]);
        });

        Schema::table('parceiros', function (Blueprint $table) {
            $table->dropIndex(['asaas_subscription_id']);
            $table->dropColumn([
                'asaas_customer_id', 'asaas_subscription_id',
                'plano', 'plano_valor', 'assinatura_status',
            ]);
        });
    }
};
