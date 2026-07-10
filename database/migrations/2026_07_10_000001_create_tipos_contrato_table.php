<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_contrato', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->string('slug', 50)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Seed default options
        $defaults = [
            ['nome' => 'CLT',        'slug' => 'clt'],
            ['nome' => 'PJ',         'slug' => 'pj'],
            ['nome' => 'Estágio',    'slug' => 'estagio'],
            ['nome' => 'Temporário', 'slug' => 'temporario'],
            ['nome' => 'Freelancer', 'slug' => 'freelancer'],
            ['nome' => 'Outros',     'slug' => 'outros'],
        ];
        foreach ($defaults as $d) {
            DB::table('tipos_contrato')->insert(array_merge($d, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        // Alter vagas.tipo_contrato from enum to varchar(50)
        Schema::table('vagas', function (Blueprint $table) {
            $table->string('tipo_contrato', 50)->default('clt')->change();
        });
    }

    public function down(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->enum('tipo_contrato', ['clt', 'pj', 'estagio', 'temporario', 'freelancer', 'outros'])->default('clt')->change();
        });

        Schema::dropIfExists('tipos_contrato');
    }
};
