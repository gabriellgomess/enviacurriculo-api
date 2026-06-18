<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_biblioteca_tipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nome');
            $table->timestamps();

            $table->index('empresa_id');
        });

        Schema::create('empresa_biblioteca_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('tipo_id')->nullable()->constrained('empresa_biblioteca_tipos')->nullOnDelete();
            $table->string('nome');
            $table->string('arquivo_path');
            $table->string('arquivo_nome');
            $table->unsignedInteger('tamanho_kb')->default(0);
            $table->timestamps();

            $table->index(['empresa_id', 'tipo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_biblioteca_documentos');
        Schema::dropIfExists('empresa_biblioteca_tipos');
    }
};
