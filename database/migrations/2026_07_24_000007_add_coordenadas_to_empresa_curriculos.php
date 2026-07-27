<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Coordenadas do currículo no banco da empresa, para o Mapa de Candidatos
 * passar a exibir todo o banco de currículos (e não apenas quem se candidatou
 * a uma vaga).
 *
 * Currículos vindos de candidatura/cópia herdam as coordenadas do candidato;
 * os inseridos manualmente são geocodificados ao salvar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_curriculos', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('estado');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        // Backfill: herda as coordenadas já existentes no cadastro do candidato
        DB::statement("
            UPDATE empresa_curriculos ec
            JOIN candidatos c ON c.id = ec.candidato_id
            SET ec.latitude = c.latitude, ec.longitude = c.longitude
            WHERE ec.candidato_id IS NOT NULL
              AND c.latitude IS NOT NULL
              AND c.longitude IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('empresa_curriculos', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
