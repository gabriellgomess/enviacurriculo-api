<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->string('nome_requisitante')->nullable()->after('horario_trabalho');
            $table->string('email_requisitante')->nullable()->after('nome_requisitante');
        });
    }

    public function down(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->dropColumn(['nome_requisitante', 'email_requisitante']);
        });
    }
};
