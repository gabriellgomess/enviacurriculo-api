<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expande o enum de status para os valores usados no painel da franquia
        // (StatusCandidatos): pendente, em_entrevista, desistiu, reposicao.
        DB::statement("ALTER TABLE envios MODIFY status ENUM(
            'enviado', 'visualizado', 'em_processo', 'em_entrevista',
            'pendente', 'aprovado', 'reprovado', 'desistiu', 'reposicao'
        ) NOT NULL DEFAULT 'enviado'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE envios MODIFY status ENUM(
            'enviado', 'visualizado', 'em_processo', 'aprovado', 'reprovado'
        ) NOT NULL DEFAULT 'enviado'");
    }
};
