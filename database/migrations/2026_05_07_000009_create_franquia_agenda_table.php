<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franquia_agenda_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->dateTime('data_inicio');
            $table->dateTime('data_fim')->nullable();
            $table->enum('tipo', ['reuniao', 'entrevista', 'visita', 'treinamento', 'outro'])->default('outro');
            $table->string('local', 255)->nullable();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('vaga_id')->nullable()->constrained('vagas')->nullOnDelete();
            $table->timestamps();

            $table->index(['franquia_id', 'data_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franquia_agenda_eventos');
    }
};
