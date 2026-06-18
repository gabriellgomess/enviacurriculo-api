<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->decimal('taxa_servico', 5, 2)->nullable()->after('nivel_vaga_id');
        });
    }

    public function down(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->dropColumn('taxa_servico');
        });
    }
};
