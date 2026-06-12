<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campos extras usados pelo cadastro manual de currículo no painel empresa
        Schema::table('empresa_curriculos', function (Blueprint $table) {
            $table->string('bairro', 100)->nullable()->after('cidade');
            $table->json('cargos_interesse')->nullable()->after('cargo_desejado');
            $table->text('experiencia_profissional')->nullable()->after('cargos_interesse');
            $table->text('educacao')->nullable()->after('experiencia_profissional');
            $table->text('habilidades')->nullable()->after('educacao');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_curriculos', function (Blueprint $table) {
            $table->dropColumn(['bairro', 'cargos_interesse', 'experiencia_profissional', 'educacao', 'habilidades']);
        });
    }
};
