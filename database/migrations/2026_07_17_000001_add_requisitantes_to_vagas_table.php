<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            // Lista de requisitantes da vaga: [{nome, email}, ...]
            // Mantém nome_requisitante/email_requisitante (primeiro da lista) por compatibilidade.
            $table->json('requisitantes')->nullable()->after('nome_requisitante');
        });
    }

    public function down(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->dropColumn('requisitantes');
        });
    }
};
