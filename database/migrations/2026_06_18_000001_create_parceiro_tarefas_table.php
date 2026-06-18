<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiro_tarefas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parceiro_id')->constrained('parceiros')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->date('data_tarefa');
            $table->boolean('concluida')->default(false);
            $table->timestamps();

            $table->index(['parceiro_id', 'data_tarefa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiro_tarefas');
    }
};
