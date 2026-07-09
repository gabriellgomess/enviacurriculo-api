<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ead_prova_respostas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->nullable()->constrained('franquias')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prova_id')->constrained('ead_provas')->cascadeOnDelete();
            $table->json('respostas');
            $table->integer('nota');
            $table->boolean('aprovado');
            $table->timestamps();
        });

        Schema::create('ead_certificados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->nullable()->constrained('franquias')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('curso_id')->constrained('ead_cursos')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ead_certificados');
        Schema::dropIfExists('ead_prova_respostas');
    }
};
