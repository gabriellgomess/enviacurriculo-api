<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\CandidatoParecer;
use App\Models\Franquia;

/**
 * PASSO 5 do plano de migração.
 *
 * `candidate_opinions` → `candidato_pareceres`. 12.474 registros.
 *
 * Decisões aplicadas:
 *   22 — migrar todos
 *    3/O — pareceres de quem não virou franquia ficam com a Unidade Matriz
 *    H — `consultant_name` NÃO é migrado quando o autor não virou franquia
 *    K — os que apontam para currículo descartado seguem o candidato mantido
 *
 * Não há arquivo: o PDF sempre foi gerado sob demanda pelo DomPDF.
 *
 * `consultant_id` referencia ec_users.id (não ec_consultants.id).
 */
class MigratePareceres extends Command
{
    protected $signature = 'ec:migrate-pareceres
                            {--matriz= : ID da franquia Unidade Matriz (obrigatório)}
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Migra os pareceres de candidato do sistema antigo';

    /** ec_consultants.id dos que viraram franquia (decisão O). */
    private const CONSULTORES = [10, 13, 15, 16, 32, 54, 112, 125, 146, 147, 171, 186];

    /** Campos do formulário antigo que vão para a coluna `dados` (JSON). */
    private const CAMPOS_DADOS = [
        'cpf', 'candidate_address', 'residence_time', 'candidate_phone',
        'candidate_email', 'desired_position', 'desired_schedule', 'birth_date',
        'marital_status', 'children_count', 'children_ages', 'education_level',
        'transportation_method', 'salary_expectation', 'previous_companies',
    ];

    public function handle(): int
    {
        $matrizId = (int) $this->option('matriz');
        $dry      = (bool) $this->option('dry-run');

        if (!$matrizId || !Franquia::find($matrizId)) {
            $this->error('Informe um --matriz=<ID> válido.');
            return 1;
        }

        $mapaCand = $this->carregarMapa('migracao-mapa-candidatos.json', 'Passo 4 (ec:migrate-candidates)');
        if ($mapaCand === null) return 1;

        $mapaVagas = $this->carregarMapa('migracao-mapa-vagas.json', 'Passo 3 (ec:migrate-vagas)');
        if ($mapaVagas === null) return 1;

        $mapaEmpresas = $this->carregarMapa('migracao-mapa-empresas.json', 'Passo 2 (ec:migrate-empresas)');
        if ($mapaEmpresas === null) return 1;

        $this->newLine();
        $this->info('PASSO 5 — Pareceres');
        $this->line('  modo:   ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->line("  matriz: {$matrizId}");
        $this->newLine();

        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error('Sem conexão com o banco antigo: ' . $e->getMessage());
            return 1;
        }

        // Quem virou franquia: user_id antigo → franquia nova
        [$autorFranquia, $autorUser] = $this->mapearAutores();

        $pareceres = DB::connection('mysql_antigo')
            ->table('candidate_opinions')->orderBy('id')->get();

        $this->line("  pareceres no banco antigo: {$pareceres->count()}");

        // ---------- Simulação ----------
        if ($dry) {
            $daFranquia = $pareceres->filter(fn($p) => isset($autorFranquia[$p->consultant_id]))->count();
            $semCand    = $pareceres->filter(fn($p) => !isset($mapaCand[$p->candidate_id]))->count();

            $this->newLine();
            $this->table(['Indicador', 'Total'], [
                ['a migrar',                       $pareceres->count()],
                ['autoria nas 12 franquias',       $daFranquia],
                ['autoria na Unidade Matriz',      $pareceres->count() - $daFranquia],
                ['sem candidato correspondente',   $semCand],
                ['com nome de autor preservado',   $daFranquia],
            ]);

            if ($semCand > 0) {
                $this->newLine();
                $this->warn("{$semCand} parecer(es) sem candidato no mapa seriam PULADOS.");
            }

            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line("  <fg=yellow>php artisan ec:migrate-pareceres --matriz={$matrizId}</>");
            return 0;
        }

        // ---------- Execução ----------
        $bar = $this->output->createProgressBar($pareceres->count());
        $bar->start();

        $migrados = 0;
        $puladas  = 0;
        $naMatriz = 0;
        $comNome  = 0;

        foreach ($pareceres as $old) {
            $candidatoId = $mapaCand[$old->candidate_id] ?? null;
            if (!$candidatoId) {
                $puladas++;
                $bar->advance();
                continue;
            }

            $franquiaId = $autorFranquia[$old->consultant_id] ?? $matrizId;
            $criadoPor  = $autorUser[$old->consultant_id] ?? $this->usuarioDaFranquia($matrizId);
            $ehFranquia = isset($autorFranquia[$old->consultant_id]);

            // Decisão H: só preserva o nome de quem virou franquia
            $dados = [];
            foreach (self::CAMPOS_DADOS as $campo) {
                if (isset($old->$campo) && $old->$campo !== null && $old->$campo !== '') {
                    $dados[$campo] = $this->talvezJson($old->$campo);
                }
            }
            if ($ehFranquia && !empty($old->consultant_name)) {
                $dados['consultant_name'] = $old->consultant_name;
                $comNome++;
            }
            $dados['origem'] = 'migracao';
            $dados['id_antigo'] = $old->id;

            CandidatoParecer::updateOrCreate(
                ['candidato_id' => $candidatoId, 'criado_por' => $criadoPor, 'texto' => (string) $old->opinion],
                [
                    'franquia_id'      => $franquiaId,
                    'vaga_id'          => $mapaVagas[$old->job_id] ?? null,
                    'empresa_id'       => $mapaEmpresas[$old->company_id] ?? null,
                    'nota'             => null,
                    'status_aprovacao' => 'aprovado',
                    'dados'            => $dados,
                ]
            );

            $migrados++;
            if (!$ehFranquia) $naMatriz++;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Concluído: {$migrados} parecer(es).");
        $this->table(['Indicador', 'Total'], [
            ['migrados',                    $migrados],
            ['autoria nas 12 franquias',    $migrados - $naMatriz],
            ['autoria na Unidade Matriz',   $naMatriz],
            ['com nome do autor no JSON',   $comNome],
            ['pulados (sem candidato)',     $puladas],
        ]);
        $this->newLine();

        return 0;
    }

    /** ec_users.id do consultor → [franquia_id, user_id] no sistema novo. */
    private function mapearAutores(): array
    {
        $antigos = DB::connection('mysql_antigo')
            ->table('ec_consultants')
            ->whereIn('id', self::CONSULTORES)
            ->get(['id', 'user_id', 'email']);

        $porFranquia = [];
        $porUser     = [];

        foreach ($antigos as $c) {
            $f = Franquia::where('email', $c->email)->first();
            if (!$f) continue;

            $ctx = DB::table('user_contexts')
                ->where('role', 'franquia')->where('context_id', $f->id)->first();

            $porFranquia[$c->user_id] = $f->id;
            if ($ctx) $porUser[$c->user_id] = $ctx->user_id;
        }

        return [$porFranquia, $porUser];
    }

    private function usuarioDaFranquia(int $franquiaId): ?int
    {
        return DB::table('user_contexts')
            ->where('role', 'franquia')->where('context_id', $franquiaId)->value('user_id');
    }

    /** Alguns campos vêm como JSON em texto (children_ages, previous_companies). */
    private function talvezJson($valor)
    {
        if (!is_string($valor)) return $valor;
        $t = trim($valor);
        if ($t === '') return $valor;

        $decodificado = json_decode($t, true);

        // Só troca quando o conteúdo era mesmo uma estrutura JSON
        return (json_last_error() === JSON_ERROR_NONE && is_array($decodificado))
            ? $decodificado
            : $valor;
    }

    private function carregarMapa(string $arquivo, string $passo): ?array
    {
        $caminho = storage_path("app/{$arquivo}");
        if (!is_file($caminho)) {
            $this->error("Mapa não encontrado: {$caminho}");
            $this->error("Rode o {$passo} antes.");
            return null;
        }
        return json_decode(file_get_contents($caminho), true) ?: [];
    }
}
