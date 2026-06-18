<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Permite vincular candidatos do banco que ainda nao tem curriculo anexado.
        // A FK para candidato_documentos e mantida.
        DB::statement('ALTER TABLE envios MODIFY curriculo_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE envios MODIFY curriculo_id BIGINT UNSIGNED NOT NULL');
    }
};
