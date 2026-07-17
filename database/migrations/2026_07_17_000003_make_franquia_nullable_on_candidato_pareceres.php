<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pareceres criados pela Administração não pertencem a nenhuma franquia.
     * franquia_id passa a ser nullable (null = Administração) e o delete da
     * franquia deixa de apagar o parecer (vira null).
     */
    public function up(): void
    {
        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->dropForeign(['franquia_id']);
        });

        DB::statement('ALTER TABLE candidato_pareceres MODIFY franquia_id BIGINT UNSIGNED NULL');

        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->foreign('franquia_id')->references('id')->on('franquias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->dropForeign(['franquia_id']);
        });

        // Atenção: rollback falha se existirem pareceres com franquia_id null
        DB::statement('ALTER TABLE candidato_pareceres MODIFY franquia_id BIGINT UNSIGNED NOT NULL');

        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->foreign('franquia_id')->references('id')->on('franquias')->cascadeOnDelete();
        });
    }
};
