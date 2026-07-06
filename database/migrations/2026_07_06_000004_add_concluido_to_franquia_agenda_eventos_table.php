<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franquia_agenda_eventos', function (Blueprint $table) {
            $table->boolean('concluido')->default(false)->after('data_fim');
        });
    }

    public function down(): void
    {
        Schema::table('franquia_agenda_eventos', function (Blueprint $table) {
            $table->dropColumn('concluido');
        });
    }
};
