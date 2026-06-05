<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franquia_servicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->decimal('valor_base', 12, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('franquia_id');
        });

        Schema::create('franquia_fornecedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('nome');
            $table->string('cnpj', 18)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('categoria', 50)->nullable();
            $table->timestamps();

            $table->index('franquia_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franquia_fornecedores');
        Schema::dropIfExists('franquia_servicos');
    }
};
