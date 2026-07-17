<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            // Tipo de contrato definido ao aprovar o candidato (slug de tipos_contrato)
            $table->string('tipo_contrato', 50)->nullable()->after('salario_aprovado');
        });
    }

    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropColumn('tipo_contrato');
        });
    }
};
