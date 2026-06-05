<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creditos_pacotes', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('label')->nullable();
            $table->integer('quantidade'); // créditos
            $table->decimal('preco', 10, 2); // R$
            $table->boolean('destaque')->default(false);
            $table->integer('ordem')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::table('creditos_pacotes')->insert([
            ['nome'=>'Pacote 10','label'=>'10 Créditos','quantidade'=>10,'preco'=>9.90,'destaque'=>false,'ordem'=>1,'active'=>true,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Pacote 25','label'=>'25 Créditos','quantidade'=>25,'preco'=>19.90,'destaque'=>false,'ordem'=>2,'active'=>true,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Pacote 50','label'=>'50 Créditos','quantidade'=>50,'preco'=>34.90,'destaque'=>true, 'ordem'=>3,'active'=>true,'created_at'=>now(),'updated_at'=>now()],
            ['nome'=>'Pacote 100','label'=>'100 Créditos','quantidade'=>100,'preco'=>59.90,'destaque'=>false,'ordem'=>4,'active'=>true,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('creditos_pacotes');
    }
};
