<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // varchar(255) nao comporta base64/URLs longas de imagem
        DB::statement('ALTER TABLE comunidade_posts MODIFY imagem_url TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE comunidade_posts MODIFY imagem_url VARCHAR(255) NULL');
    }
};
