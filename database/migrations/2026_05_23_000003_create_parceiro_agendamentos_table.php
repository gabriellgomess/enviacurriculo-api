<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiro_agendamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parceiro_id')->constrained('parceiros')->cascadeOnDelete();
            $table->string('cliente');
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('servico');
            $table->dateTime('data');
            $table->unsignedSmallInteger('duracao_min')->default(60);
            $table->enum('status', ['pendente', 'confirmado', 'concluido', 'cancelado'])->default('pendente');
            $table->text('observacao')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiro_agendamentos');
    }
};
