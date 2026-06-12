<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Vagas: campos do contrato da empresa ─────────────────────────
        Schema::table('vagas', function (Blueprint $table) {
            $table->enum('canal', ['agencia', 'plataforma', 'ambos'])->default('plataforma')->after('status');
            $table->boolean('ocultar_empresa')->default(false)->after('canal');
            $table->boolean('ocultar_endereco')->default(false)->after('ocultar_empresa');
            $table->string('genero', 20)->nullable()->after('ocultar_endereco');
            $table->string('turno', 20)->nullable()->after('genero');
            $table->string('horario_trabalho', 50)->nullable()->after('turno');
            $table->string('logradouro')->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('logradouro');
        });

        // Amplia o enum de status (mantém os valores existentes)
        DB::statement("ALTER TABLE vagas MODIFY COLUMN status
            ENUM('rascunho', 'publicada', 'pausada', 'em_andamento', 'fechada', 'cancelada')
            NOT NULL DEFAULT 'rascunho'");

        // Benefícios da vaga (catálogo)
        Schema::create('vaga_beneficios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vaga_id')->constrained('vagas')->cascadeOnDelete();
            $table->foreignId('beneficio_id')->constrained('beneficios_catalogo')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vaga_id', 'beneficio_id']);
        });

        // ── Kanban de candidatos ─────────────────────────────────────────
        Schema::create('kanban_etapas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete(); // null = global
            $table->string('nome', 50);
            $table->string('cor', 9)->default('#94a3b8');
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('etapa_sistema', 30)->nullable();
            $table->timestamps();
        });

        DB::table('kanban_etapas')->insert([
            ['empresa_id' => null, 'nome' => 'Recebido',   'cor' => '#94a3b8', 'ordem' => 1, 'etapa_sistema' => 'recebido',   'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => null, 'nome' => 'Entrevista', 'cor' => '#3b82f6', 'ordem' => 2, 'etapa_sistema' => 'entrevista', 'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => null, 'nome' => 'Teste',      'cor' => '#a855f7', 'ordem' => 3, 'etapa_sistema' => 'teste',      'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => null, 'nome' => 'Aprovado',   'cor' => '#22c55e', 'ordem' => 4, 'etapa_sistema' => 'aprovado',   'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => null, 'nome' => 'Reprovado',  'cor' => '#ef4444', 'ordem' => 5, 'etapa_sistema' => 'reprovado',  'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => null, 'nome' => 'Desistente', 'cor' => '#6b7280', 'ordem' => 6, 'etapa_sistema' => 'desistente', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Envios: campos do processo seletivo da empresa ───────────────
        Schema::table('envios', function (Blueprint $table) {
            $table->foreignId('kanban_etapa_id')->nullable()->after('status')
                  ->constrained('kanban_etapas')->nullOnDelete();
            $table->string('origem', 20)->default('plataforma')->after('kanban_etapa_id');
            $table->enum('status_empresa', ['pendente', 'aprovado', 'reprovado', 'desistiu', 'reposicao'])
                  ->default('pendente')->after('origem');
            $table->text('observacao')->nullable()->after('status_empresa');
            $table->decimal('salario_aprovado', 10, 2)->nullable()->after('observacao');
            $table->date('data_admissao')->nullable()->after('salario_aprovado');
            $table->date('data_saida')->nullable()->after('data_admissao');
        });

        // Pareceres anexados ao envio pela empresa
        Schema::create('envio_pareceres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('envios')->cascadeOnDelete();
            $table->text('texto');
            $table->string('arquivo_path')->nullable();
            $table->string('arquivo_nome')->nullable();
            $table->string('autor')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('envio_id');
        });

        // ── Banco de currículos da empresa ───────────────────────────────
        Schema::create('empresa_curriculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('kanban_etapa_id')->nullable()->constrained('kanban_etapas')->nullOnDelete();
            $table->string('nome');
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('cpf', 14)->nullable();
            $table->string('cargo_desejado')->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('estado', 2)->nullable();
            $table->enum('origem', ['plataforma', 'franquia', 'manual', 'copia_base'])->default('manual');
            $table->string('arquivo_path')->nullable();
            $table->string('arquivo_nome')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'origem']);
            $table->unique(['empresa_id', 'candidato_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_curriculos');
        Schema::dropIfExists('envio_pareceres');

        Schema::table('envios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kanban_etapa_id');
            $table->dropColumn(['origem', 'status_empresa', 'observacao', 'salario_aprovado', 'data_admissao', 'data_saida']);
        });

        Schema::dropIfExists('kanban_etapas');
        Schema::dropIfExists('vaga_beneficios');

        Schema::table('vagas', function (Blueprint $table) {
            $table->dropColumn(['canal', 'ocultar_empresa', 'ocultar_endereco', 'genero', 'turno', 'horario_trabalho', 'logradouro', 'numero']);
        });

        DB::statement("ALTER TABLE vagas MODIFY COLUMN status
            ENUM('rascunho', 'publicada', 'pausada', 'fechada') NOT NULL DEFAULT 'rascunho'");
    }
};
