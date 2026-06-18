<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_colaboradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nome_completo');
            $table->string('cpf', 20)->nullable();
            $table->string('cargo')->nullable();
            $table->string('departamento')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->date('data_admissao')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->decimal('salario', 12, 2)->nullable();
            $table->enum('status', ['ativo', 'ferias', 'afastado', 'desligado'])->default('ativo');
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_colaboradores');
    }
};
