<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('codigo', 20)->unique()->nullable()->after('id');
            $table->string('nome_fantasia')->nullable()->after('razao_social')->change(); // garantir nullable

            // Classificação
            $table->enum('tipo_empresa', ['matriz', 'filial'])->default('matriz')->after('nome_fantasia');
            $table->enum('tipo_acesso', ['plataforma', 'agencia', 'ambos'])->nullable()->after('tipo_empresa');
            $table->enum('plano', ['basico', 'padrao', 'premium'])->nullable()->after('tipo_acesso');
            $table->enum('status', ['pendente', 'aprovado', 'rejeitado'])->default('pendente')->after('plano');
            $table->text('descricao')->nullable()->after('status');

            // Endereço completo
            $table->string('cep', 9)->nullable()->after('email');
            $table->string('rua')->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('rua');
            $table->string('complemento', 100)->nullable()->after('numero');
            $table->string('bairro', 100)->nullable()->after('complemento');
            // cidade e estado já existem

            // Configurações agência
            $table->integer('prazo_vencimento_dias')->default(30)->after('estado');
            $table->integer('reposicao_dias')->default(30)->after('prazo_vencimento_dias');

            // Franquia responsável
            $table->foreignId('franquia_id')->nullable()->after('reposicao_dias')
                ->constrained('franquias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropForeign(['franquia_id']);
            $table->dropUnique(['codigo']);
            $table->dropColumn([
                'codigo', 'tipo_empresa', 'tipo_acesso', 'plano', 'status', 'descricao',
                'cep', 'rua', 'numero', 'complemento', 'bairro',
                'prazo_vencimento_dias', 'reposicao_dias', 'franquia_id',
            ]);
        });
    }
};
