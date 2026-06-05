<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parceiros', function (Blueprint $table) {
            $table->string('nome_empresa')->nullable()->after('razao_social');
            $table->text('descricao')->nullable()->after('categoria');
            $table->string('telefone', 20)->nullable()->after('descricao');
            $table->string('email')->nullable()->after('telefone');
            $table->string('logo_url')->nullable()->after('email');
            $table->string('bairro', 100)->nullable()->after('cidade');
        });
    }

    public function down(): void
    {
        Schema::table('parceiros', function (Blueprint $table) {
            $table->dropColumn(['nome_empresa', 'descricao', 'telefone', 'email', 'logo_url', 'bairro']);
        });
    }
};
