<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parceiros', function (Blueprint $table) {
            $table->foreignId('franquia_id')->nullable()->after('user_id')->constrained('franquias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('parceiros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('franquia_id');
        });
    }
};
