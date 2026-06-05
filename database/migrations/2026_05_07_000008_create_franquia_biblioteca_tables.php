<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franquia_arquivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('nome');
            $table->string('arquivo_path');
            $table->string('arquivo_nome');
            $table->unsignedInteger('tamanho_kb')->default(0);
            $table->string('categoria', 50)->nullable();
            $table->timestamps();

            $table->index('franquia_id');
        });

        Schema::create('franquia_manuais', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->string('arquivo_path');
            $table->string('arquivo_nome');
            $table->unsignedInteger('tamanho_kb')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franquia_manuais');
        Schema::dropIfExists('franquia_arquivos');
    }
};
