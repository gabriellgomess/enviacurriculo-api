<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiro_visualizacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parceiro_id')->constrained('parceiros')->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->string('empresa_nome')->nullable();
            $table->string('usuario_nome')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->enum('tipo', ['visualizacao', 'telefone', 'email', 'proposta'])->default('visualizacao');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiro_visualizacoes');
    }
};
