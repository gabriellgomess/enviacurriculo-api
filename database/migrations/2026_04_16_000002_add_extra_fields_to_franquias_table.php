<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franquias', function (Blueprint $table) {
            // Identificação
            $table->string('codigo', 20)->unique()->nullable()->after('id');
            $table->enum('tipo', ['premium', 'start'])->default('start')->after('codigo');

            // Dados pessoais do franqueado
            $table->string('cpf', 14)->nullable()->after('responsavel');
            $table->date('data_nascimento')->nullable()->after('cpf');
            $table->date('data_inicio_parceria')->nullable()->after('data_nascimento');
            $table->date('data_termino_parceria')->nullable()->after('data_inicio_parceria');

            // Endereço da empresa (endereço pessoal já existe: cep, logradouro, etc.)
            $table->string('cep_empresa', 9)->nullable()->after('longitude');
            $table->string('logradouro_empresa')->nullable()->after('cep_empresa');
            $table->string('numero_empresa', 20)->nullable()->after('logradouro_empresa');
            $table->string('complemento_empresa', 100)->nullable()->after('numero_empresa');
            $table->string('bairro_empresa', 100)->nullable()->after('complemento_empresa');
            $table->string('cidade_empresa')->nullable()->after('bairro_empresa');
            $table->string('estado_empresa', 2)->nullable()->after('cidade_empresa');
            $table->decimal('latitude_empresa', 10, 7)->nullable()->after('estado_empresa');
            $table->decimal('longitude_empresa', 10, 7)->nullable()->after('latitude_empresa');

            // Dados bancários
            $table->string('nome_banco')->nullable()->after('longitude_empresa');
            $table->string('codigo_banco', 10)->nullable()->after('nome_banco');
            $table->string('agencia', 20)->nullable()->after('codigo_banco');
            $table->string('numero_conta', 20)->nullable()->after('agencia');
            $table->enum('tipo_conta', ['corrente', 'poupanca'])->nullable()->after('numero_conta');
            $table->string('chave_pix')->nullable()->after('tipo_conta');

            // Permissões do painel da franquia
            $table->json('menus_permitidos')->nullable()->after('chave_pix');
        });
    }

    public function down(): void
    {
        Schema::table('franquias', function (Blueprint $table) {
            $table->dropColumn([
                'codigo', 'tipo', 'cpf', 'data_nascimento',
                'data_inicio_parceria', 'data_termino_parceria',
                'cep_empresa', 'logradouro_empresa', 'numero_empresa',
                'complemento_empresa', 'bairro_empresa', 'cidade_empresa',
                'estado_empresa', 'latitude_empresa', 'longitude_empresa',
                'nome_banco', 'codigo_banco', 'agencia', 'numero_conta',
                'tipo_conta', 'chave_pix', 'menus_permitidos',
            ]);
        });
    }
};
