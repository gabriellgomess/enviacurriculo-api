<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('user_name');
            $table->enum('user_type', ['admin', 'franquia'])->default('admin');
            $table->text('mensagem');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_followups');
    }
};
