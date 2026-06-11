<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tipos de metas (Vendas, Vagas Fechadas, etc.)
        Schema::create('tipos_metas', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('descricao')->nullable();
            $table->enum('unidade', ['moeda', 'quantidade'])->default('moeda');
            $table->timestamps();
        });

        // Metas por franquia
        Schema::create('metas_franquias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->foreignId('tipo_meta_id')->nullable()->constrained('tipos_metas')->nullOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->decimal('valor_meta', 12, 2);
            $table->decimal('valor_atual', 12, 2)->default(0); // manual p/ quantidade; moeda é calculada
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();
            $table->enum('status', ['ativa', 'pausada', 'concluida', 'cancelada'])->default('ativa');
            $table->timestamps();

            $table->index(['franquia_id', 'status']);
        });

        // Registro de acessos (login/logout) de todos os painéis
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_type', 20); // admin | empresa | franquia | candidato | parceiro
            $table->enum('action', ['login', 'logout']);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('metas_franquias');
        Schema::dropIfExists('tipos_metas');
    }
};
