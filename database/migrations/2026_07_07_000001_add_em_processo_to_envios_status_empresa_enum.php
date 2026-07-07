<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Frontend oferece "Em processo" como opção de status, mas o enum não incluía esse valor.
        DB::statement("ALTER TABLE envios MODIFY COLUMN status_empresa
            ENUM('pendente', 'em_processo', 'aprovado', 'reprovado', 'desistiu', 'reposicao') NOT NULL DEFAULT 'pendente'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE envios MODIFY COLUMN status_empresa
            ENUM('pendente', 'aprovado', 'reprovado', 'desistiu', 'reposicao') NOT NULL DEFAULT 'pendente'");
    }
};
