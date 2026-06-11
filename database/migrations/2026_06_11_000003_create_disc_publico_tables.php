<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Questões do teste DISC (grupos de 4 palavras, cada uma com um fator)
        Schema::create('disc_questoes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('grupo')->unique();
            $table->string('opcao_a', 100);
            $table->string('opcao_b', 100);
            $table->string('opcao_c', 100);
            $table->string('opcao_d', 100);
            $table->enum('fator_a', ['D', 'I', 'S', 'C']);
            $table->enum('fator_b', ['D', 'I', 'S', 'C']);
            $table->enum('fator_c', ['D', 'I', 'S', 'C']);
            $table->enum('fator_d', ['D', 'I', 'S', 'C']);
            $table->timestamps();
        });

        // Convites de teste DISC enviados a leads (link público por token)
        Schema::create('disc_convites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('franquia_leads')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->enum('status', ['pendente', 'respondido'])->default('pendente');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // Resultados do teste DISC respondido por leads
        Schema::create('disc_lead_resultados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convite_id')->constrained('disc_convites')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('franquia_leads')->cascadeOnDelete();
            $table->unsignedTinyInteger('score_d')->default(0);
            $table->unsignedTinyInteger('score_i')->default(0);
            $table->unsignedTinyInteger('score_s')->default(0);
            $table->unsignedTinyInteger('score_c')->default(0);
            $table->enum('perfil_dominante', ['D', 'I', 'S', 'C']);
            $table->json('respostas')->nullable();
            $table->timestamps();

            $table->unique('convite_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disc_lead_resultados');
        Schema::dropIfExists('disc_convites');
        Schema::dropIfExists('disc_questoes');
    }
};
