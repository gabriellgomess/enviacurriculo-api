<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comunidade_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('conteudo');
            $table->string('imagem_url')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('comunidade_reacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('comunidade_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 20)->default('like');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('comunidade_comentarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('comunidade_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('conteudo');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comunidade_comentarios');
        Schema::dropIfExists('comunidade_reacoes');
        Schema::dropIfExists('comunidade_posts');
    }
};
