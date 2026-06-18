<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_curriculos', function (Blueprint $table) {
            $table->string('arquivo_cnh_path')->nullable()->after('arquivo_nome');
            $table->string('arquivo_cnh_nome')->nullable()->after('arquivo_cnh_path');
            $table->string('arquivo_ctps_path')->nullable()->after('arquivo_cnh_nome');
            $table->string('arquivo_ctps_nome')->nullable()->after('arquivo_ctps_path');
            // diplomas sao multiplos: array de { path, nome }
            $table->json('diplomas')->nullable()->after('arquivo_ctps_nome');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_curriculos', function (Blueprint $table) {
            $table->dropColumn(['arquivo_cnh_path', 'arquivo_cnh_nome', 'arquivo_ctps_path', 'arquivo_ctps_nome', 'diplomas']);
        });
    }
};
