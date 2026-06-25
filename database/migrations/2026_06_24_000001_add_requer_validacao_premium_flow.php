<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->boolean('requer_validacao_premium')->default(false)->after('status');
        });

        Schema::table('envio_pareceres', function (Blueprint $table) {
            // enviado = liberado direto; pendente_validacao = aguarda a franquia premium;
            // validado/rejeitado = decisão da premium.
            $table->enum('status', ['enviado', 'pendente_validacao', 'validado', 'rejeitado'])
                  ->default('enviado')->after('texto');
            $table->text('motivo_validacao')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('vagas', function (Blueprint $table) {
            $table->dropColumn('requer_validacao_premium');
        });
        Schema::table('envio_pareceres', function (Blueprint $table) {
            $table->dropColumn(['status', 'motivo_validacao']);
        });
    }
};
