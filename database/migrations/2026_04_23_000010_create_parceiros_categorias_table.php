<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiros_categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->integer('ordem')->default(0);
            $table->timestamps();
        });

        DB::table('parceiros_categorias')->insert([
            ['nome'=>'Contabilidade','ordem'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Autoescola','ordem'=>2,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Cartório','ordem'=>3,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Fotografia','ordem'=>4,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Capacitação','ordem'=>5,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Seguros','ordem'=>6,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Saúde','ordem'=>7,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Beleza','ordem'=>8,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Outros','ordem'=>99,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiros_categorias');
    }
};
