<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franquia_chamados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao');
            $table->enum('categoria', ['sistema', 'financeiro', 'comercial', 'operacional', 'outro'])->default('outro');
            $table->enum('prioridade', ['baixa', 'media', 'alta', 'urgente'])->default('media');
            $table->enum('status', ['aberto', 'em_atendimento', 'fechado'])->default('aberto');
            $table->text('resposta')->nullable();
            $table->timestamps();

            $table->index(['franquia_id', 'status']);
        });

        Schema::create('franquia_chamado_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chamado_id')->constrained('franquia_chamados')->cascadeOnDelete();
            $table->text('mensagem');
            $table->enum('autor', ['franquia', 'suporte'])->default('franquia');
            $table->timestamp('created_at')->useCurrent();

            $table->index('chamado_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franquia_chamado_mensagens');
        Schema::dropIfExists('franquia_chamados');
    }
};
