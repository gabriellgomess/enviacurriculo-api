<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franquia_contas_pagar', function (Blueprint $table) {
            $table->unsignedBigInteger('franquia_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('franquia_contas_pagar', function (Blueprint $table) {
            $table->unsignedBigInteger('franquia_id')->nullable(false)->change();
        });
    }
};
