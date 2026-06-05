<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comunidade_posts', function (Blueprint $table) {
            $table->string('titulo', 255)->nullable()->after('user_id');
            $table->enum('tipo', ['duvida', 'compartilhamento', 'aviso'])->nullable()->after('titulo');
        });
    }

    public function down(): void
    {
        Schema::table('comunidade_posts', function (Blueprint $table) {
            $table->dropColumn(['titulo', 'tipo']);
        });
    }
};
