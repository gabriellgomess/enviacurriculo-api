<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convites DISC passam a aceitar candidatos (além de leads)
        Schema::table('disc_convites', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_id')->nullable()->change();
            $table->foreignId('candidato_id')->nullable()->after('lead_id')
                  ->constrained('candidatos')->cascadeOnDelete();
            $table->foreignId('vaga_envio_id')->nullable()->after('candidato_id')
                  ->constrained('envios')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->after('vaga_envio_id')
                  ->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('criado_por')->nullable()->after('empresa_id')
                  ->constrained('users')->nullOnDelete();

            $table->index(['empresa_id', 'status']);
        });

        // Resultado DISC de candidato ganha vínculo com o convite e respostas
        Schema::table('candidato_disc', function (Blueprint $table) {
            $table->foreignId('convite_id')->nullable()->after('aplicado_por')
                  ->constrained('disc_convites')->nullOnDelete();
            $table->json('respostas')->nullable()->after('score_c');
        });

        // Testes práticos/técnicos agendados pela empresa
        Schema::create('testes_agendados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->foreignId('vaga_envio_id')->nullable()->constrained('envios')->nullOnDelete();
            $table->foreignId('vaga_id')->nullable()->constrained('vagas')->nullOnDelete();
            $table->string('tipo_teste', 30)->default('pratico');
            $table->dateTime('data');
            $table->string('local')->nullable();
            $table->enum('status', ['agendado', 'realizado', 'cancelado', 'nao_compareceu'])->default('agendado');
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testes_agendados');

        Schema::table('candidato_disc', function (Blueprint $table) {
            $table->dropConstrainedForeignId('convite_id');
            $table->dropColumn('respostas');
        });

        Schema::table('disc_convites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('candidato_id');
            $table->dropConstrainedForeignId('vaga_envio_id');
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropConstrainedForeignId('criado_por');
        });
    }
};
