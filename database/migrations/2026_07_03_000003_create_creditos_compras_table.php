<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creditos_compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->foreignId('pacote_id')->nullable()->constrained('creditos_pacotes')->nullOnDelete();
            $table->integer('quantidade');
            $table->decimal('valor', 10, 2);
            $table->string('cpf', 11);
            $table->string('nome');
            $table->enum('status', ['pendente', 'pago', 'cancelado', 'expirado'])->default('pendente');
            $table->string('asaas_payment_id')->nullable()->index();
            $table->text('qr_code')->nullable();
            $table->longText('qr_code_image')->nullable();
            $table->timestamp('expiration_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creditos_compras');
    }
};
