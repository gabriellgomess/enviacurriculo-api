<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_taxas_servico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('nivel_vaga_id')->constrained('niveis_vagas')->cascadeOnDelete();
            $table->decimal('percentual', 5, 2);
            $table->timestamps();

            $table->unique(['empresa_id', 'nivel_vaga_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_taxas_servico');
    }
};
