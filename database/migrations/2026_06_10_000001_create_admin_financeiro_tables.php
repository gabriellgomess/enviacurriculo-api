<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Configurações financeiras por tipo de franquia
        // (mensalidade, royalties, marketing, comissão, imposto)
        Schema::create('financeiro_configs', function (Blueprint $table) {
            $table->id();
            $table->enum('categoria', [
                'mensalidade',
                'tx_royalties',
                'tx_marketing',
                'percentual_comissao',
                'percentual_imposto',
            ]);
            $table->enum('tipo_franquia', ['premium', 'start', 's_start']);
            $table->decimal('valor', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['categoria', 'tipo_franquia']);
        });

        // Percentuais de comissão por tipo de indicação
        Schema::create('comissao_tipos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 50)->unique(); // recrutamento | parceiro | candidatos
            $table->decimal('percentual', 5, 2)->default(0);
            $table->timestamps();
        });

        DB::table('comissao_tipos')->insert([
            ['tipo' => 'recrutamento', 'percentual' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['tipo' => 'parceiro',     'percentual' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['tipo' => 'candidatos',   'percentual' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Notas fiscais da franqueadora (admin) — emitidas e recebidas
        Schema::create('admin_notas_fiscais', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['emitida', 'recebida']);
            $table->string('numero', 50);
            $table->string('razao_social');
            $table->string('cnpj_cpf', 20)->nullable();
            $table->decimal('valor', 12, 2)->default(0);
            $table->date('data_emissao');
            $table->date('data_vencimento')->nullable();
            $table->text('descricao')->nullable();
            $table->enum('status', ['pendente', 'paga', 'cancelada'])->default('pendente');
            $table->string('arquivo_path')->nullable();
            $table->string('arquivo_nome')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tipo', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notas_fiscais');
        Schema::dropIfExists('comissao_tipos');
        Schema::dropIfExists('financeiro_configs');
    }
};
