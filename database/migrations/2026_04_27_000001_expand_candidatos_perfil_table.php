<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->text('apresentacao')->nullable()->after('cargo_desejado');
            $table->string('linkedin')->nullable()->after('apresentacao');
            $table->string('github')->nullable()->after('linkedin');
            $table->string('portfolio_url')->nullable()->after('github');
            $table->decimal('pretensao_salarial', 10, 2)->nullable()->after('portfolio_url');
            $table->string('disponibilidade', 20)->nullable()->after('pretensao_salarial');
            $table->boolean('pcd')->default(false)->after('disponibilidade');
            $table->decimal('latitude', 10, 7)->nullable()->after('pcd');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropColumn([
                'apresentacao', 'linkedin', 'github', 'portfolio_url',
                'pretensao_salarial', 'disponibilidade', 'pcd',
                'latitude', 'longitude',
            ]);
        });
    }
};
