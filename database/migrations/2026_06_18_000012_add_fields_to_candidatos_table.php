<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->string('idiomas', 500)->nullable()->after('habilidades');
            $table->text('informacoes_adicionais')->nullable()->after('idiomas');
            $table->json('cargos_interesse')->nullable()->after('informacoes_adicionais');
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropColumn(['idiomas', 'informacoes_adicionais', 'cargos_interesse']);
        });
    }
};
