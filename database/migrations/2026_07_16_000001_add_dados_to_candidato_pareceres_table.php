<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidato_pareceres', function (Blueprint $table) {
            // Dados completos do formulário de parecer (filhos, escolaridade,
            // deslocamento, salários, experiências etc.)
            $table->json('dados')->nullable()->after('nota');
        });
    }

    public function down(): void
    {
        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->dropColumn('dados');
        });
    }
};
