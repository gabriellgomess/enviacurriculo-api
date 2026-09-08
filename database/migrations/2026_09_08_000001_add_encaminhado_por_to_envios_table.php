<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem encaminhou o candidato à vaga.
 *
 * `franquia_id` já diz de qual unidade partiu o envio, mas com o módulo
 * multiusuário uma unidade passa a ter titular e assistentes, e o cliente
 * precisa acompanhar a produção de cada um. O relatório continua mostrando a
 * unidade (Matriz, por exemplo) e ganha uma coluna com o nome do usuário.
 *
 * O nome é `encaminhado_por`, e não `criado_por`, porque a coluna guarda o
 * operador que fez o encaminhamento. Candidatura espontânea pelo feed não tem
 * operador e fica nula de propósito.
 *
 * nullOnDelete: se o usuário for removido, o envio permanece — perder o
 * histórico de encaminhamento seria pior do que perder o nome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->foreignId('encaminhado_por')
                  ->nullable()
                  ->after('franquia_id')
                  ->constrained('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('encaminhado_por');
        });
    }
};
