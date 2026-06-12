<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franquia_notificacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('corpo')->nullable();
            $table->boolean('lida')->default(false);
            $table->timestamps();

            $table->index(['franquia_id', 'lida']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franquia_notificacoes');
    }
};
