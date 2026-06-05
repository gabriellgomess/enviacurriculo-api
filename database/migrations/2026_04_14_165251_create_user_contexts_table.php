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
        Schema::create('user_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['empresa', 'franquia', 'candidato', 'parceiro']);
            $table->unsignedBigInteger('context_id'); // id da empresa/franquia/candidato/parceiro
            $table->timestamps();

            $table->unique(['user_id', 'role', 'context_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_contexts');
    }
};
