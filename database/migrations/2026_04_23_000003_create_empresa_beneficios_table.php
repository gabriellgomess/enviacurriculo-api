<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_beneficios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('beneficio_id')->constrained('beneficios_catalogo')->cascadeOnDelete();
            $table->unique(['empresa_id', 'beneficio_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_beneficios');
    }
};
