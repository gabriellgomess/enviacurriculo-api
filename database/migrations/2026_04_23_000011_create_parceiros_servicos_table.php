<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiros_servicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parceiro_id')->constrained('parceiros')->cascadeOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('parceiros_categorias')->nullOnDelete();
            $table->string('nome_servico');
            $table->string('proposta_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiros_servicos');
    }
};
