<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Limpa os dados de teste dos módulos Financeiro e Biblioteca.
 *
 * Por padrão roda em modo simulação (dry-run): apenas mostra o que seria
 * apagado. Só executa de verdade com --force.
 *
 *   php artisan ec:limpar-dados-teste            # simula
 *   php artisan ec:limpar-dados-teste --force    # executa
 *   php artisan ec:limpar-dados-teste --force --only=biblioteca
 */
class LimparDadosTeste extends Command
{
    protected $signature = 'ec:limpar-dados-teste
                            {--force : Executa de verdade (sem isso, apenas simula)}
                            {--only= : Limita o escopo: financeiro | biblioteca}
                            {--keep-files : Não apaga os arquivos físicos do storage}';

    protected $description = 'Remove os lançamentos e arquivos de teste dos módulos Financeiro e Biblioteca';

    /**
     * Tabelas a limpar. 'arquivo' aponta a coluna com o caminho físico, se houver.
     */
    private array $alvos = [
        'financeiro' => [
            ['tabela' => 'franquia_contas_pagar',   'arquivo' => null],
            ['tabela' => 'franquia_contas_receber', 'arquivo' => null],
            ['tabela' => 'franquia_faturamentos',   'arquivo' => null],
            ['tabela' => 'franquia_notas_fiscais',  'arquivo' => null],
            ['tabela' => 'empresa_faturamentos',    'arquivo' => null],
            ['tabela' => 'admin_notas_fiscais',     'arquivo' => 'arquivo_path'],
            ['tabela' => 'financeiro_configs',      'arquivo' => null],
        ],
        'biblioteca' => [
            ['tabela' => 'franquia_arquivos',              'arquivo' => 'arquivo_path'],
            ['tabela' => 'franquia_manuais',               'arquivo' => 'arquivo_path'],
            ['tabela' => 'empresa_biblioteca_documentos',  'arquivo' => 'arquivo_path'],
        ],
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $only  = $this->option('only');

        if ($only && !isset($this->alvos[$only])) {
            $this->error("Escopo inválido: '{$only}'. Use 'financeiro' ou 'biblioteca'.");
            return 1;
        }

        $grupos = $only ? [$only => $this->alvos[$only]] : $this->alvos;

        $this->newLine();
        $this->info($force ? '>> MODO EXECUÇÃO — os dados serão apagados' : '>> MODO SIMULAÇÃO — nada será apagado');
        $this->newLine();

        // ---------- Levantamento ----------
        $linhas = [];
        $totalRegistros = 0;
        $arquivos = [];

        foreach ($grupos as $grupo => $tabelas) {
            foreach ($tabelas as $alvo) {
                $tabela = $alvo['tabela'];

                if (!$this->tabelaExiste($tabela)) {
                    $linhas[] = [$grupo, $tabela, '—', 'não existe'];
                    continue;
                }

                $qtd = DB::table($tabela)->count();
                $totalRegistros += $qtd;

                $qtdArquivos = 0;
                if ($alvo['arquivo'] && $qtd > 0) {
                    $paths = DB::table($tabela)
                        ->whereNotNull($alvo['arquivo'])
                        ->where($alvo['arquivo'], '<>', '')
                        ->pluck($alvo['arquivo'])
                        ->all();
                    $arquivos = array_merge($arquivos, $paths);
                    $qtdArquivos = count($paths);
                }

                $linhas[] = [
                    $grupo,
                    $tabela,
                    number_format($qtd, 0, ',', '.'),
                    $qtdArquivos ? "{$qtdArquivos} arquivo(s)" : '—',
                ];
            }
        }

        $this->table(['Módulo', 'Tabela', 'Registros', 'Storage'], $linhas);

        if ($totalRegistros === 0) {
            $this->info('Nada a apagar. As tabelas já estão vazias.');
            return 0;
        }

        $this->newLine();
        $this->warn("Total: {$totalRegistros} registro(s) e " . count($arquivos) . ' arquivo(s).');

        // ---------- Simulação ----------
        if (!$force) {
            $this->newLine();
            $this->line('Nenhuma alteração foi feita. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:limpar-dados-teste --force</>');
            return 0;
        }

        // ---------- Confirmação ----------
        $this->newLine();
        $this->error('Esta operação é IRREVERSÍVEL. Faça backup do banco antes de continuar.');

        if (!$this->confirm('Digite sim para apagar definitivamente', false)) {
            $this->info('Cancelado. Nada foi apagado.');
            return 0;
        }

        // ---------- Execução ----------
        $apagadosBanco = 0;
        $apagadosDisco = 0;

        DB::transaction(function () use ($grupos, &$apagadosBanco, &$arquivos) {
            // Ordem inversa evita bloqueio por chave estrangeira
            foreach (array_reverse($grupos) as $tabelas) {
                foreach (array_reverse($tabelas) as $alvo) {
                    if (!$this->tabelaExiste($alvo['tabela'])) {
                        continue;
                    }
                    $apagadosBanco += DB::table($alvo['tabela'])->delete();
                }
            }
        });

        // Arquivos físicos
        if (!$this->option('keep-files')) {
            foreach ($arquivos as $path) {
                foreach (['public', 'local'] as $disco) {
                    if (Storage::disk($disco)->exists($path)) {
                        Storage::disk($disco)->delete($path);
                        $apagadosDisco++;
                        break;
                    }
                }
            }
        }

        // Recria os 3 tipos de comissão que a migration semeia.
        // Sem eles o módulo de comissão fica sem os tipos base.
        if (!$only || $only === 'financeiro') {
            if ($this->tabelaExiste('comissao_tipos')) {
                DB::table('comissao_tipos')->delete();
                DB::table('comissao_tipos')->insert([
                    ['tipo' => 'recrutamento', 'percentual' => 0, 'created_at' => now(), 'updated_at' => now()],
                    ['tipo' => 'parceiro',     'percentual' => 0, 'created_at' => now(), 'updated_at' => now()],
                    ['tipo' => 'candidatos',   'percentual' => 0, 'created_at' => now(), 'updated_at' => now()],
                ]);
                $this->line('  · comissao_tipos redefinido para os 3 tipos base com 0%');
            }
        }

        $this->newLine();
        $this->info("Concluído: {$apagadosBanco} registro(s) e {$apagadosDisco} arquivo(s) removidos.");

        if (!$only || $only === 'financeiro') {
            $this->newLine();
            $this->warn('Lembre-se de recadastrar as configurações financeiras (mensalidade,');
            $this->warn('royalties, marketing, comissão e imposto) antes de usar o módulo.');
        }

        return 0;
    }

    private function tabelaExiste(string $tabela): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($tabela);
    }
}
