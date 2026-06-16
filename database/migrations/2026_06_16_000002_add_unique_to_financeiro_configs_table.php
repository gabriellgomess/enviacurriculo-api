<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financeiro_configs', function (Blueprint $table) {
            $table->unique(['categoria', 'tipo_franquia'], 'financeiro_configs_categoria_tipo_unique');
        });
    }

    public function down(): void
    {
        Schema::table('financeiro_configs', function (Blueprint $table) {
            $table->dropUnique('financeiro_configs_categoria_tipo_unique');
        });
    }
};
