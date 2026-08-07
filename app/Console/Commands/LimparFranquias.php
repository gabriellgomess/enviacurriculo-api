<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Franquia;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserContext;

/**
 * Prepara o terreno para o PASSO 1 da migração.
 *
 * Apaga DEFINITIVAMENTE todas as franquias do sistema novo, exceto a Unidade
 * Matriz, para que `ec:migrate-franquias` recrie as 12 a partir do sistema
 * antigo — sem duplicar cadastros feitos à mão com e-mail divergente.
 *
 * ATENÇÃO: é DELETE real, não soft delete. Em cascata o banco apaga:
 *   franquia_documentos, franquia_arquivos, franquia_chamados, franquia_agenda,
 *   franquia_notificacoes, franquia_contas_pagar, franquia_contas_receber,
 *   franquia_faturamentos, franquia_notas_fiscais, vaga_franquia_compartilhada
 *   e as tabelas de cadastro e T&D da franquia.
 *
 * E zera o franquia_id de: empresas, vagas, candidatos, candidato_pareceres,
 * candidato_followups, parceiros, empresa_arquivos e resultados de EAD.
 *
 * Uso:
 *   php artisan ec:limpar-franquias                  # simula
 *   php artisan ec:limpar-franquias --force          # executa
 *   php artisan ec:limpar-franquias --force --manter=22,23
 */
class LimparFranquias extends Command
{
    protected $signature = 'ec:limpar-franquias
                            {--force : Executa de verdade (sem isso, apenas simula)}
                            {--manter=22 : IDs a preservar, separados por vírgula}
                            {--manter-usuarios : Não remove os usuários das franquias apagadas}';

    protected $description = 'Apaga definitivamente as franquias do sistema novo, exceto as preservadas';

    /** Tabelas que perdem os registros em cascata. */
    private array $cascata = [
        'franquia_documentos', 'franquia_arquivos', 'franquia_chamados',
        'franquia_agenda_eventos', 'franquia_notificacoes', 'franquia_contas_pagar',
        'franquia_contas_receber', 'franquia_faturamentos', 'franquia_notas_fiscais',
        'vaga_franquia_compartilhada', 'franquia_servicos', 'franquia_fornecedores',
        'franquia_onboarding_progresso', 'metas_franquias', 'franquia_leads',
    ];

    /** Tabelas que apenas ficam com franquia_id nulo. */
    private array $desvincula = [
        'empresas', 'vagas', 'candidatos', 'candidato_pareceres',
        'candidato_followups', 'parceiros', 'empresa_arquivos',
    ];

    /** Evita quebrar em tabela que não tem a coluna (ex.: franquia_leads). */
    private function temColunaFranquia(string $tabela): bool
    {
        return Schema::hasTable($tabela) && Schema::hasColumn($tabela, 'franquia_id');
    }

    public function handle(): int
    {
        $force  = (bool) $this->option('force');
        $manter = array_filter(array_map('intval', explode(',', (string) $this->option('manter'))));

        if (empty($manter)) {
            $this->error('Informe ao menos um ID em --manter (normalmente a Unidade Matriz).');
            return 1;
        }

        $this->newLine();
        $this->info($force ? '>> MODO EXECUÇÃO — delete real' : '>> MODO SIMULAÇÃO — nada será apagado');
        $this->line('   preservando: ' . implode(', ', $manter));
        $this->newLine();

        $alvos = Franquia::withTrashed()->whereNotIn('id', $manter)->orderBy('id')->get();

        if ($alvos->isEmpty()) {
            $this->info('Nenhuma franquia a remover.');
            return 0;
        }

        $ids = $alvos->pluck('id')->all();

        // ---------- Impacto ----------
        $linhas = [];
        foreach ($alvos as $f) {
            $linhas[] = [
                $f->id,
                $f->codigo ?: '—',
                mb_strimwidth($f->nome, 0, 30, '…'),
                $f->deleted_at ? 'já excluída' : 'ativa',
                DB::table('empresas')->where('franquia_id', $f->id)->count(),
                DB::table('vagas')->where('franquia_id', $f->id)->count(),
                DB::table('candidato_pareceres')->where('franquia_id', $f->id)->count(),
            ];
        }
        $this->table(['id', 'código', 'nome', 'situação', 'empresas', 'vagas', 'pareceres'], $linhas);

        // Cascata
        $this->newLine();
        $this->line('<fg=red>Registros que serão APAGADOS em cascata:</>');
        $totalCascata = 0;
        foreach ($this->cascata as $t) {
            if (!$this->temColunaFranquia($t)) continue;
            $q = DB::table($t)->whereIn('franquia_id', $ids)->count();
            if ($q > 0) {
                $this->line("  · {$t}: {$q}");
                $totalCascata += $q;
            }
        }
        if ($totalCascata === 0) $this->line('  (nenhum)');

        // Desvinculação
        $this->newLine();
        $this->line('<fg=yellow>Registros que ficarão com franquia_id nulo:</>');
        $totalDesv = 0;
        foreach ($this->desvincula as $t) {
            if (!$this->temColunaFranquia($t)) continue;
            $q = DB::table($t)->whereIn('franquia_id', $ids)->count();
            if ($q > 0) {
                $this->line("  · {$t}: {$q}");
                $totalDesv += $q;
            }
        }
        if ($totalDesv === 0) $this->line('  (nenhum)');

        // Usuários
        $contextos = UserContext::where('role', 'franquia')->whereIn('context_id', $ids)->get();
        $userIds   = $contextos->pluck('user_id')->unique()->values();

        $comOutroPapel = UserRole::whereIn('user_id', $userIds)
            ->where('role', '<>', 'franquia')
            ->pluck('user_id')->unique();

        $paraApagar = $userIds->diff($comOutroPapel);

        $this->newLine();
        $this->line('<fg=cyan>Usuários vinculados:</>');
        $this->line("  · contextos de franquia: {$contextos->count()}");
        $this->line("  · usuários exclusivos de franquia (serão apagados): {$paraApagar->count()}");
        if ($comOutroPapel->isNotEmpty()) {
            $this->line("  · com outro papel (mantidos, perdem só o papel de franquia): {$comOutroPapel->count()}");
        }

        $this->newLine();
        $this->warn("Total: {$alvos->count()} franquia(s), {$totalCascata} registro(s) em cascata, "
                  . "{$totalDesv} desvinculado(s).");

        // ---------- Simulação ----------
        if (!$force) {
            $this->newLine();
            $this->line('Nada foi alterado. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:limpar-franquias --force --manter=' . implode(',', $manter) . '</>');
            return 0;
        }

        // ---------- Confirmação ----------
        $this->newLine();
        $this->error('DELETE REAL E IRREVERSÍVEL. Faça backup do banco antes.');
        if (!$this->confirm('Confirma a exclusão definitiva?', false)) {
            $this->info('Cancelado. Nada foi apagado.');
            return 0;
        }

        // ---------- Execução ----------
        $apagadas = 0;

        DB::transaction(function () use ($alvos, $ids, $paraApagar, &$apagadas) {
            // Contextos e papéis de franquia
            UserContext::where('role', 'franquia')->whereIn('context_id', $ids)->delete();

            if (!$this->option('manter-usuarios') && $paraApagar->isNotEmpty()) {
                UserRole::whereIn('user_id', $paraApagar)->delete();
                User::whereIn('id', $paraApagar)->forceDelete();
            } else {
                UserRole::whereIn('user_id', $paraApagar)->where('role', 'franquia')->delete();
            }

            // Delete real — dispara a cascata do banco
            foreach ($alvos as $f) {
                $f->forceDelete();
                $apagadas++;
            }
        });

        $this->newLine();
        $this->info("Concluído: {$apagadas} franquia(s) apagada(s) definitivamente.");

        $restantes = Franquia::withTrashed()->count();
        $this->line("Franquias restantes no banco: {$restantes}");
        $this->newLine();
        $this->info('Próximo passo:');
        $this->line('  <fg=yellow>php artisan ec:migrate-franquias --dry-run</>');
        $this->newLine();

        return 0;
    }
}
