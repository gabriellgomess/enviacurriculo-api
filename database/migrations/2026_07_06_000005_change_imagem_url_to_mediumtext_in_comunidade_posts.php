<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // TEXT comporta só ~64KB — insuficiente para uma imagem em base64 (até ~6.7MB,
        // já que o frontend permite upload de até 5MB). MEDIUMTEXT comporta até 16MB.
        DB::statement('ALTER TABLE comunidade_posts MODIFY imagem_url MEDIUMTEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE comunidade_posts MODIFY imagem_url TEXT NULL');
    }
};
