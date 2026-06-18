<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_curriculos', function (Blueprint $table) {
            $table->string('cep', 9)->nullable()->after('bairro');
            $table->string('rua', 255)->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('rua');
            $table->string('complemento', 100)->nullable()->after('numero');
            $table->string('tipo_cnh', 10)->nullable()->after('complemento');
            $table->text('informacoes_pessoais')->nullable()->after('tipo_cnh');
            $table->string('idiomas', 500)->nullable()->after('informacoes_pessoais');
            $table->text('informacoes_adicionais')->nullable()->after('idiomas');
            $table->boolean('active')->default(true)->after('informacoes_adicionais');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_curriculos', function (Blueprint $table) {
            $table->dropColumn(['cep', 'rua', 'numero', 'complemento', 'tipo_cnh', 'informacoes_pessoais', 'idiomas', 'informacoes_adicionais', 'active']);
        });
    }
};
