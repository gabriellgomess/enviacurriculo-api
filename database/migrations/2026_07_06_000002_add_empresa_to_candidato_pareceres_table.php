<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->after('vaga_id')
                ->constrained('empresas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidato_pareceres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_id');
        });
    }
};
