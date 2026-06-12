<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vincula a conta a receber ao envio faturado (controle de "já faturado")
        Schema::table('franquia_contas_receber', function (Blueprint $table) {
            $table->foreignId('envio_id')->nullable()->after('franquia_id')
                  ->constrained('envios')->nullOnDelete();
            $table->unique('envio_id');
        });
    }

    public function down(): void
    {
        Schema::table('franquia_contas_receber', function (Blueprint $table) {
            $table->dropConstrainedForeignId('envio_id');
        });
    }
};
