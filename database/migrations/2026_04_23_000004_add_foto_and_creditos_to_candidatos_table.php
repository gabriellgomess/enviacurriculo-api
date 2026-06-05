<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->string('foto_url')->nullable()->after('cargo_desejado');
            $table->integer('creditos')->default(0)->after('foto_url');
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropColumn(['foto_url', 'creditos']);
        });
    }
};
