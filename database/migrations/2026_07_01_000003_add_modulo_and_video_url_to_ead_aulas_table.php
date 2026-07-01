<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ead_aulas', function (Blueprint $table) {
            $table->string('modulo', 100)->nullable()->after('curso_id');
            $table->string('video_url')->nullable()->after('titulo');
        });
    }

    public function down(): void
    {
        Schema::table('ead_aulas', function (Blueprint $table) {
            $table->dropColumn(['modulo', 'video_url']);
        });
    }
};
