<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->foreignId('vaga_id')->constrained('vagas')->cascadeOnDelete();
            $table->foreignId('curriculo_id')->constrained('candidato_documentos')->cascadeOnDelete();
            $table->text('mensagem')->nullable();
            $table->enum('status', ['enviado', 'visualizado', 'em_processo', 'aprovado', 'reprovado'])
                  ->default('enviado');
            $table->timestamp('visualizado_em')->nullable();
            $table->timestamps();

            $table->unique(['candidato_id', 'vaga_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios');
    }
};
