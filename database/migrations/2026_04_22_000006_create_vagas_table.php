<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vagas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->text('requisitos')->nullable();
            $table->text('beneficios')->nullable();

            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('franquia_id')->nullable()->constrained('franquias')->nullOnDelete();
            $table->foreignId('nivel_vaga_id')->nullable()->constrained('niveis_vagas')->nullOnDelete();

            $table->enum('tipo_contrato', ['clt', 'pj', 'estagio', 'temporario', 'freelancer', 'outros'])->default('clt');
            $table->enum('regime_trabalho', ['presencial', 'remoto', 'hibrido'])->default('presencial');
            $table->string('carga_horaria')->nullable();

            $table->decimal('salario_min', 10, 2)->nullable();
            $table->decimal('salario_max', 10, 2)->nullable();
            $table->boolean('exibir_salario')->default(true);

            $table->string('cep')->nullable();
            $table->string('cidade')->nullable();
            $table->string('estado', 2)->nullable();
            $table->string('bairro')->nullable();

            $table->integer('quantidade_vagas')->default(1);
            $table->enum('status', ['rascunho', 'publicada', 'pausada', 'fechada'])->default('rascunho');

            $table->date('data_abertura')->nullable();
            $table->date('data_fechamento')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vagas');
    }
};
