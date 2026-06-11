<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leads do "Seja Franqueado" (home)
        Schema::create('franquia_leads', function (Blueprint $table) {
            $table->id();
            $table->string('nome_completo');
            $table->string('email');
            $table->string('telefone', 20);
            $table->boolean('experiencia_rh')->default(false);
            $table->string('bairro', 100)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('estado', 2)->nullable();
            $table->string('capital_disponivel', 50)->nullable();
            $table->boolean('capital_confirmado')->default(false);
            $table->string('tempo_inicio', 50)->nullable();
            $table->text('motivacao')->nullable();
            $table->string('indicacao')->nullable();
            $table->enum('status', ['novo', 'em_contato', 'qualificado', 'convertido', 'descartado'])->default('novo');
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franquia_leads');
    }
};
