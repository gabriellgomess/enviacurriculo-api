<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creditos_movimentacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->enum('tipo', ['compra', 'uso', 'bonus', 'estorno']);
            $table->integer('quantidade'); // positivo (compra/bonus/estorno) ou negativo (uso)
            $table->integer('saldo_antes');
            $table->integer('saldo_depois');
            $table->string('descricao');
            $table->string('referencia_tipo')->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->timestamps();

            $table->index(['candidato_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creditos_movimentacoes');
    }
};
