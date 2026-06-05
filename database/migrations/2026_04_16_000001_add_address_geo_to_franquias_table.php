<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franquias', function (Blueprint $table) {
            $table->text('descricao')->nullable()->after('responsavel');
            $table->string('cep', 9)->nullable()->after('telefone');
            $table->string('logradouro')->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('logradouro');
            $table->string('complemento', 100)->nullable()->after('numero');
            $table->string('bairro', 100)->nullable()->after('complemento');
            // cidade e estado já existem — lat/lng vêm depois
            $table->decimal('latitude', 10, 7)->nullable()->after('estado');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('franquias', function (Blueprint $table) {
            $table->dropColumn([
                'descricao', 'cep', 'logradouro', 'numero',
                'complemento', 'bairro', 'latitude', 'longitude',
            ]);
        });
    }
};
