<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->string('telefone', 20)->nullable()->after('user_id');
            $table->string('cep', 9)->nullable()->after('telefone');
            $table->string('rua')->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('rua');
            $table->string('complemento', 100)->nullable()->after('numero');
            $table->string('bairro', 100)->nullable()->after('complemento');
            // cidade e estado já existem
            $table->string('tipo_cnh', 10)->nullable()->after('estado');
            $table->text('experiencia_profissional')->nullable()->after('tipo_cnh');
            $table->text('educacao')->nullable()->after('experiencia_profissional');
            $table->text('habilidades')->nullable()->after('educacao');
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropColumn([
                'telefone', 'cep', 'rua', 'numero', 'complemento', 'bairro',
                'tipo_cnh', 'experiencia_profissional', 'educacao', 'habilidades',
            ]);
        });
    }
};
