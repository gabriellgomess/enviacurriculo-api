<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda_tarefas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->date('data_tarefa');
            $table->string('hora', 5)->nullable();
            $table->boolean('concluida')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'data_tarefa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_tarefas');
    }
};
