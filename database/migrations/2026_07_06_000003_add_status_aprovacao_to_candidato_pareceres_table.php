<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->enum('status_aprovacao', ['pendente', 'aprovado', 'reprovado'])
                ->default('pendente')->after('nota');
        });
    }

    public function down(): void
    {
        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->dropColumn('status_aprovacao');
        });
    }
};
