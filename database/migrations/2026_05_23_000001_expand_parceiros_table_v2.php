<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parceiros', function (Blueprint $table) {
            $table->string('cep', 9)->nullable()->after('bairro');
            $table->string('rua', 255)->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('rua');
            $table->json('especialidades')->nullable()->after('numero');
        });
    }

    public function down(): void
    {
        Schema::table('parceiros', function (Blueprint $table) {
            $table->dropColumn(['cep', 'rua', 'numero', 'especialidades']);
        });
    }
};
