<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidato_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->enum('tipo', ['curriculo', 'cnh', 'ctps', 'diploma']);
            $table->string('arquivo_path');
            $table->string('arquivo_nome');
            $table->integer('tamanho_kb')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidato_documentos');
    }
};
