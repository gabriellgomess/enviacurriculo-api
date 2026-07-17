<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_faturamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedTinyInteger('mes');   // 1-12
            $table->unsignedSmallInteger('ano');  // ex: 2026
            $table->decimal('valor', 12, 2);
            $table->timestamps();

            $table->unique(['empresa_id', 'mes', 'ano']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_faturamentos');
    }
};
