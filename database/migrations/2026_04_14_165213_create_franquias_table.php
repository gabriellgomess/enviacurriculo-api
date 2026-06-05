<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('franquias', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('cnpj', 18)->unique()->nullable();
            $table->string('responsavel')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('cidade')->nullable();
            $table->string('estado', 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('franquias');
    }
};
