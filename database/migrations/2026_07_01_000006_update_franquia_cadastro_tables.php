<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franquia_servicos', function (Blueprint $table) {
            $table->enum('tipo', ['credito', 'avulso', 'recorrente'])->default('credito')->after('descricao');
        });

        Schema::table('franquia_fornecedores', function (Blueprint $table) {
            $table->string('endereco')->nullable()->after('telefone');
            $table->text('observacao')->nullable()->after('endereco');
            $table->boolean('ativo')->default(true)->after('observacao');
        });
    }

    public function down(): void
    {
        Schema::table('franquia_servicos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });

        Schema::table('franquia_fornecedores', function (Blueprint $table) {
            $table->dropColumn(['endereco', 'observacao', 'ativo']);
        });
    }
};
