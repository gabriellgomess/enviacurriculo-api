<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_agenda_tarefas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->date('data_tarefa');
            $table->string('hora', 5)->nullable();
            $table->boolean('concluida')->default(false);
            $table->timestamps();

            $table->index(['empresa_id', 'data_tarefa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_agenda_tarefas');
    }
};
