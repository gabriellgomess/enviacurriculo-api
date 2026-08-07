<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Zera o banco novo para que a migração seja a única fonte de dados.
 *
 * O sistema esteve em homologação e todo o conteúdo atual é de teste. Este
 * comando limpa tudo que é migrável ou operacional, preservando:
 *   - usuários com papel 'admin' (e suas permissões)
 *   - catálogos e configurações (níveis de vaga, benefícios, categorias de
 *     parceiro, tipos de contrato, etapas de kanban, questões DISC, pacotes
 *     de crédito, tipos de comissão)
 *   - tabelas de sistema (migrations, cache, filas, sessões, tokens)
 *
 * Depois deste comando, a sequência é sempre a mesma:
 *   ec:criar-unidade-matriz → ec:migrate-franquias → ec:migrate-empresas → ...
 *
 * Uso:
 *   php artisan ec:reset-migracao                # simula
 *   php artisan ec:reset-migracao --force        # executa
 *   php artisan ec:reset-migracao --force --com-arquivos
 */
class ResetMigracao extends Command
{
    protected $signature = 'ec:reset-migracao
                            {--force : Executa de verdade (sem isso, apenas simula)}
                            {--com-arquivos : Apaga também os arquivos do storage}
                            {--manter-financeiro : Preserva financeiro_configs e admin_notas_fiscais}';

    protected $description = 'Zera os dados de homologação do banco novo antes da migração';

    /** Tabelas a esvaziar, agrupadas por domínio. */
    private array $grupos = [
        'Franquias' => [
            'franquia_agenda_eventos', 'franquia_arquivos', 'franquia_chamado_mensagens',
            'franquia_chamados', 'franquia_contas_pagar', 'franquia_contas_receber',
            'franquia_documentos', 'franquia_faturamentos', 'franquia_fornecedores',
            'franquia_leads', 'franquia_manuais', 'franquia_notas_fiscais',
            'franquia_notificacoes', 'franquia_onboarding_progresso', 'franquia_servicos',
            'metas_franquias', 'access_logs', 'franquias',
        ],
        'Empresas' => [
            'empresa_agenda_tarefas', 'empresa_arquivos', 'empresa_beneficios',
            'empresa_biblioteca_documentos', 'empresa_biblioteca_tipos',
            'empresa_colaborador_beneficios', 'empresa_colaboradores', 'empresa_curriculos',
            'empresa_entrevistas', 'empresa_faturamentos', 'empresa_followups',
            'empresa_notificacoes', 'empresa_sub_usuarios', 'empresa_taxas_servico',
            'empresas',
        ],
        'Vagas' => [
            'vaga_beneficios', 'vaga_documentos', 'vaga_franquia_compartilhada', 'vagas',
        ],
        'Candidatos' => [
            'candidato_disc', 'candidato_documentos', 'candidato_followups',
            'candidato_pareceres', 'creditos_compras', 'creditos_movimentacoes',
            'candidatos',
        ],
        'Envios' => ['envio_pareceres', 'envios'],
        'Parceiros' => [
            'parceiro_agendamentos', 'parceiro_tarefas', 'parceiro_visualizacoes',
            'parceiros_servicos', 'parceiros',
        ],
        'Comunidade' => ['comunidade_reacoes', 'comunidade_comentarios', 'comunidade_posts'],
        'EAD (resultados)' => ['ead_certificados', 'ead_progresso', 'ead_prova_respostas'],
        'Diversos' => [
            'agenda_tarefas', 'testes_agendados', 'disc_convites', 'disc_lead_resultados',
            'contatos_site', 'email_opt_outs',
        ],
    ];

    /** Só limpo com --manter-financeiro ausente. */
    private array $financeiro = ['admin_notas_fiscais', 'financeiro_configs'];

    /** Pastas do storage público criadas pelo uso do sistema. */
    private array $pastas = [
        'candidatos', 'empresas', 'vagas', 'franquias', 'parceiros', 'comunidade',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->newLine();
        $this->info($force ? '>> MODO EXECUÇÃO — os dados serão apagados' : '>> MODO SIMULAÇÃO — nada será apagado');
        $this->line('   banco: ' . config('database.connections.' . config('database.default') . '.database'));
        $this->newLine();

        $grupos = $this->grupos;
        if (!$this->option('manter-financeiro')) {
            $grupos['Financeiro'] = $this->financeiro;
        }

        // ---------- Levantamento ----------
        $linhas = [];
        $total  = 0;

        foreach ($grupos as $grupo => $tabelas) {
            $sub = 0;
            foreach ($tabelas as $t) {
                if (!Schema::hasTable($t)) continue;
                $sub += DB::table($t)->count();
            }
            $linhas[] = [$grupo, count($tabelas), number_format($sub, 0, ',', '.')];
            $total += $sub;
        }

        // Usuários: preservar admins
        $adminIds = DB::table('user_roles')->where('role', 'admin')->pluck('user_id')->unique();
        $totalUsers = DB::table('users')->count();
        $paraApagar = $totalUsers - DB::table('users')->whereIn('id', $adminIds)->count();

        $linhas[] = ['Usuários (exceto admin)', 3, number_format($paraApagar, 0, ',', '.')];
        $total += $paraApagar;

        $this->table(['Domínio', 'Tabelas', 'Registros'], $linhas);

        $this->newLine();
        $this->line('<fg=green>Preservado:</>');
        $this->line('  · ' . $adminIds->count() . ' usuário(s) admin, com papéis e permissões');
        $this->line('  · catálogos: níveis de vaga, benefícios, categorias de parceiro,');
        $this->line('    tipos de contrato, etapas de kanban, questões DISC, pacotes de crédito');
        $this->line('  · conteúdo do EAD (cursos, aulas, provas e questões)');
        if ($this->option('manter-financeiro')) {
            $this->line('  · financeiro_configs e admin_notas_fiscais');
        }

        $this->newLine();
        $this->warn("Total a apagar: " . number_format($total, 0, ',', '.') . ' registro(s).');

        if ($total === 0) {
            $this->info('Banco já está limpo.');
            return 0;
        }

        // ---------- Simulação ----------
        if (!$force) {
            $this->newLine();
            $this->line('Nada foi alterado. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:reset-migracao --force</>');
            return 0;
        }

        // ---------- Confirmação ----------
        $this->newLine();
        $this->error('OPERAÇÃO IRREVERSÍVEL. Confirme que o backup do banco foi feito.');
        if (!$this->confirm('Zerar os dados de homologação?', false)) {
            $this->info('Cancelado. Nada foi apagado.');
            return 0;
        }

        // ---------- Execução ----------
        $apagados = 0;

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($grupos as $grupo => $tabelas) {
                foreach ($tabelas as $t) {
                    if (!Schema::hasTable($t)) continue;
                    $apagados += DB::table($t)->count();
                    DB::table($t)->truncate();
                }
                $this->line("  · {$grupo}");
            }

            // Usuários não-admin, com papéis e contextos
            $manter = $adminIds->all();
            DB::table('user_contexts')->whereNotIn('user_id', $manter ?: [0])->delete();
            DB::table('user_roles')->whereNotIn('user_id', $manter ?: [0])->delete();
            DB::table('users')->whereNotIn('id', $manter ?: [0])->delete();
            $this->line('  · Usuários (exceto admin)');

            // Sessões e tokens perdem sentido
            foreach (['sessions', 'personal_access_tokens', 'password_reset_tokens'] as $t) {
                if (Schema::hasTable($t)) DB::table($t)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // ---------- Arquivos ----------
        if ($this->option('com-arquivos')) {
            $this->newLine();
            foreach ($this->pastas as $pasta) {
                if (Storage::disk('public')->exists($pasta)) {
                    Storage::disk('public')->deleteDirectory($pasta);
                    $this->line("  · storage/public/{$pasta} removido");
                }
            }
        }

        $this->newLine();
        $this->info('Banco zerado. ' . number_format($apagados, 0, ',', '.') . ' registro(s) removidos.');
        $this->newLine();
        $this->info('Sequência da migração:');
        $this->line('  1. <fg=yellow>php artisan ec:criar-unidade-matriz</>');
        $this->line('  2. <fg=yellow>php artisan ec:migrate-franquias --dry-run</>');
        $this->line('  3. <fg=yellow>php artisan ec:migrate-franquias</>');
        $this->newLine();

        return 0;
    }
}
