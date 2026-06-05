<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaga_franquia_compartilhada', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vaga_id')->constrained('vagas')->cascadeOnDelete();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vaga_id', 'franquia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaga_franquia_compartilhada');
    }
};
