<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contatos_site', function (Blueprint $table) {
            $table->id();
            $table->string('nome_completo');
            $table->string('telefone', 20)->nullable();
            $table->string('email');
            $table->text('mensagem');
            $table->enum('status', ['novo', 'lido', 'respondido'])->default('novo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contatos_site');
    }
};
