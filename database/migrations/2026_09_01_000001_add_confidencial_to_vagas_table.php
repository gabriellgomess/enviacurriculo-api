<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vaga confidencial — marcada só pelo Admin ou por uma franquia Premium ao
 * cadastrar (não pela empresa, que já tem suas próprias flags ocultar_*).
 * Quando marcada, qualquer OUTRA franquia (diferente de vagas.franquia_id)
 * vê a vaga sem o nome da empresa e sem o bairro — só cargo e cidade. Quem
 * cadastrou e o Admin sempre veem tudo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->boolean('confidencial')->default(false)->after('ocultar_endereco_agencia');
        });
    }

    public function down(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->dropColumn('confidencial');
        });
    }
};
