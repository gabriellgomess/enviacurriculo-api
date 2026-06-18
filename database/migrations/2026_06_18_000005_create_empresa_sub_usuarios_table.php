<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_sub_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('menus_permitidos')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique('user_id');
            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_sub_usuarios');
    }
};
