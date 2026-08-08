<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga a franquia ao consultor de origem no sistema antigo.
 *
 * A migração usava o e-mail como chave, mas e-mail muda: a franquia da
 * Achiliany foi padronizada para o domínio da empresa depois de migrada, e o
 * comando deixou de reconhecê-la — o que quebraria a atribuição das vagas e
 * criaria uma franquia duplicada na próxima execução.
 *
 * `ec_consultants.id` é estável e resolve isso de forma definitiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franquias', function (Blueprint $table) {
            $table->unsignedBigInteger('id_antigo')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('franquias', function (Blueprint $table) {
            $table->dropUnique(['id_antigo']);
            $table->dropColumn('id_antigo');
        });
    }
};
