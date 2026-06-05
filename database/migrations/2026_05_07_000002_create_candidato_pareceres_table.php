<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidato_pareceres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->foreignId('vaga_id')->nullable()->constrained('vagas')->nullOnDelete();
            $table->foreignId('criado_por')->constrained('users')->cascadeOnDelete();
            $table->text('texto');
            $table->unsignedTinyInteger('nota')->nullable(); // 1-5
            $table->timestamps();

            $table->index(['franquia_id', 'candidato_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidato_pareceres');
    }
};
