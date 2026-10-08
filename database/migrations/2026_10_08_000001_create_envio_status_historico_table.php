<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de status do vínculo (envio): cada mudança de status vira uma
 * linha, com quando aconteceu e quem fez.
 *
 * Antes desta tabela o sistema só guardava o status ATUAL — a data em que um
 * candidato foi aprovado se perdia na primeira edição seguinte do vínculo.
 * O Desempenho da Rede conta as contratações pelo mês da aprovação, então
 * essa data precisa existir. O passado é preenchido pelo comando
 * envios:historico-inicial, com estimado = true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envio_status_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('envios')->cascadeOnDelete();
            $table->string('status_anterior', 30)->nullable(); // null = criação do vínculo
            $table->string('status_novo', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('painel', 20)->nullable(); // franquia, empresa, admin, candidato, sistema
            // true = reconstruído a partir das datas que existiam (não registrado na hora)
            $table->boolean('estimado')->default(false);
            $table->timestamp('ocorrido_em');
            $table->timestamp('created_at')->nullable();

            $table->index(['envio_id', 'status_novo']);
            $table->index(['status_novo', 'ocorrido_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envio_status_historico');
    }
};
