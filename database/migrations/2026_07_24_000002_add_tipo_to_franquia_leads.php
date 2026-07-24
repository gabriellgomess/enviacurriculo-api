<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franquia_leads', function (Blueprint $table) {
            // 'franquia' = interessado em abrir franquia (Seja Franqueado)
            // 'parceiro' = parceiro que efetivou o cadastro na plataforma
            $table->string('tipo', 20)->default('franquia')->after('id');
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('franquia_leads', function (Blueprint $table) {
            $table->dropIndex(['tipo']);
            $table->dropColumn('tipo');
        });
    }
};
