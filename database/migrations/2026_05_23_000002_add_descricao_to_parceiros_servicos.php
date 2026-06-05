<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parceiros_servicos', function (Blueprint $table) {
            $table->text('descricao')->nullable()->after('nome_servico');
        });
    }

    public function down(): void
    {
        Schema::table('parceiros_servicos', function (Blueprint $table) {
            $table->dropColumn('descricao');
        });
    }
};
