<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->foreignId('franquia_id')->nullable()->after('user_id')->constrained('franquias')->nullOnDelete();
            $table->foreignId('criado_por')->nullable()->after('franquia_id')->constrained('users')->nullOnDelete();
            $table->index('franquia_id');
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('franquia_id');
            $table->dropConstrainedForeignId('criado_por');
        });
    }
};
