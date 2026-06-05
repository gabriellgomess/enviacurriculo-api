<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidato_documentos', function (Blueprint $table) {
            $table->boolean('ativo')->default(false)->after('tamanho_kb');
        });
    }

    public function down(): void
    {
        Schema::table('candidato_documentos', function (Blueprint $table) {
            $table->dropColumn('ativo');
        });
    }
};
