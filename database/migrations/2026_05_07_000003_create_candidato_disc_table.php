<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidato_disc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->foreignId('aplicado_por')->constrained('users')->cascadeOnDelete();
            $table->enum('perfil_dominante', ['D', 'I', 'S', 'C']);
            $table->unsignedTinyInteger('score_d')->default(0);
            $table->unsignedTinyInteger('score_i')->default(0);
            $table->unsignedTinyInteger('score_s')->default(0);
            $table->unsignedTinyInteger('score_c')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidato_disc');
    }
};
