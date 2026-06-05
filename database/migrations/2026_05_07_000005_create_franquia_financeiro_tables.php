<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franquia_contas_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('descricao');
            $table->decimal('valor', 12, 2);
            $table->date('data_vencimento');
            $table->date('data_pagamento')->nullable();
            $table->string('categoria', 50)->default('outros');
            $table->enum('status', ['pendente', 'pago', 'cancelado'])->default('pendente');
            $table->string('fornecedor_nome', 255)->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['franquia_id', 'status']);
        });

        Schema::create('franquia_contas_receber', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('candidato_nome', 255)->nullable();
            $table->string('vaga_nome', 255)->nullable();
            $table->string('empresa_nome', 255)->nullable();
            $table->string('franchise_nome', 255)->nullable();
            $table->decimal('salario', 12, 2)->nullable();
            $table->decimal('taxa_servico', 6, 2)->default(100);
            $table->decimal('valor_bruto', 12, 2)->default(0);
            $table->decimal('imposto_perc', 5, 2)->default(0);
            $table->decimal('imposto_valor', 12, 2)->default(0);
            $table->decimal('royalties_perc', 5, 2)->default(0);
            $table->decimal('royalties_valor', 12, 2)->default(0);
            $table->decimal('marketing_perc', 5, 2)->default(0);
            $table->decimal('marketing_valor', 12, 2)->default(0);
            $table->decimal('comissao_perc', 5, 2)->default(0);
            $table->decimal('comissao_valor', 12, 2)->default(0);
            $table->decimal('comissao_s_start_perc', 5, 2)->default(0);
            $table->decimal('comissao_s_start_valor', 12, 2)->default(0);
            $table->decimal('valor_liquido', 12, 2)->default(0);
            $table->date('data_faturamento')->nullable();
            $table->date('data_vencimento')->nullable();
            $table->date('data_reposicao')->nullable();
            $table->boolean('is_sstart')->default(false);
            $table->enum('status', ['pendente', 'pago', 'cancelado'])->default('pendente');
            $table->timestamps();

            $table->index(['franquia_id', 'status']);
        });

        Schema::create('franquia_faturamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('descricao');
            $table->enum('tipo', ['comissao_vaga', 'taxa_mensal', 'royalties', 'outros'])->default('outros');
            $table->decimal('valor', 12, 2);
            $table->enum('status', ['pendente', 'pago'])->default('pendente');
            $table->date('data_referencia')->nullable();
            $table->date('data_pagamento')->nullable();
            $table->string('empresa_nome', 255)->nullable();
            $table->timestamps();

            $table->index(['franquia_id', 'status']);
        });

        Schema::create('franquia_notas_fiscais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('numero_nf', 50)->nullable();
            $table->string('descricao');
            $table->decimal('valor', 12, 2);
            $table->enum('status', ['emitida', 'cancelada', 'pendente'])->default('pendente');
            $table->date('data_emissao')->nullable();
            $table->timestamps();

            $table->index('franquia_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franquia_notas_fiscais');
        Schema::dropIfExists('franquia_faturamentos');
        Schema::dropIfExists('franquia_contas_receber');
        Schema::dropIfExists('franquia_contas_pagar');
    }
};
