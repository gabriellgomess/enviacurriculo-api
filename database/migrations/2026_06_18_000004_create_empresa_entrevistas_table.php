<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_entrevistas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('vaga_id')->nullable()->constrained('vagas')->nullOnDelete();
            $table->dateTime('data')->nullable();
            $table->string('local')->nullable();
            $table->enum('modalidade', ['presencial', 'video', 'telefone'])->default('presencial');
            $table->string('link_video')->nullable();
            $table->string('consultor_nome')->nullable();
            $table->text('observacao')->nullable();
            $table->enum('status', ['agendada', 'realizada', 'cancelada', 'nao_compareceu'])->default('agendada');
            $table->timestamps();

            $table->index(['empresa_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_entrevistas');
    }
};
