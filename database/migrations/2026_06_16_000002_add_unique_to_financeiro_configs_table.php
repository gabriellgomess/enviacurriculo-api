<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicatas mantendo apenas o registro mais recente de cada (categoria, tipo_franquia)
        DB::statement('
            DELETE fc1 FROM financeiro_configs fc1
            INNER JOIN financeiro_configs fc2
                ON fc1.categoria = fc2.categoria
               AND fc1.tipo_franquia = fc2.tipo_franquia
               AND fc1.id < fc2.id
        ');

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
