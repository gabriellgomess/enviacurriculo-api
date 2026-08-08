<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Envio;
use App\Models\CandidatoDocumento;

/**
 * PASSO 6 do plano de migração.
 *
 * `candidate_jobs` → `envios`. 19.334 registros.
 *
 * Decisões aplicadas:
 *   23 — migrar todos
 *    C — admission_date vai para `data_admissao` (coluna já existente)
 *    K — os que apontam para currículo descartado seguem o candidato mantido
 *
 * `candidate_id` referencia ec_curriculos.id (não ec_candidates.id).
 * Não há pares candidato+vaga duplicados, então o índice único de `envios`
 * não é violado.
 */
class MigrateEnvios extends Command
{
    use \App\Console\Commands\Concerns\PreservaDatas;

    protected $signature = 'ec:migrate-envios
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Migra os vínculos candidato–vaga do sistema antigo';

    private const STATUS = [
        'rejected' => 'reprovado',
        'quit'     => 'desistiu',
        'approved' => 'aprovado',
        'pending'  => 'enviado',
    ];

    /** O enum de status_empresa não tem 'enviado'. */
    private const STATUS_EMPRESA = [
        'rejected' => 'reprovado',
        'quit'     => 'desistiu',
        'approved' => 'aprovado',
        'pending'  => 'pendente',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $mapaCand = $this->carregarMapa('mapa-candidatos.json', 'Passo 4');
        if ($mapaCand === null) return 1;

        $mapaVagas = $this->carregarMapa('mapa-vagas.json', 'Passo 3');
        if ($mapaVagas === null) return 1;

        // ec_users.id do consultor (antigo) => franquia_id no sistema novo
        $mapaAutores = $this->mapearAutores();

        $this->newLine();
        $this->info('PASSO 6 — Envios');
        $this->line('  modo: ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->line('  franquias mapeadas: ' . count($mapaAutores));
        $this->newLine();

        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error('Sem conexão com o banco antigo: ' . $e->getMessage());
            return 1;
        }

        $vinculos = DB::connection('mysql_antigo')
            ->table('candidate_jobs')->orderBy('id')->get();

        $this->line("  vínculos no banco antigo: {$vinculos->count()}");

        // ---------- Simulação ----------
        if ($dry) {
            $semCand = $vinculos->filter(fn($v) => !isset($mapaCand[$v->candidate_id]))->count();
            $semVaga = $vinculos->filter(fn($v) => !isset($mapaVagas[$v->job_id]))->count();
            $porStatus = $vinculos->groupBy(fn($v) => self::STATUS[$v->status] ?? 'enviado')->map->count();

            $this->newLine();
            $this->table(['Situação no sistema novo', 'Total'],
                $porStatus->map(fn($q, $s) => [$s, $q])->values()->all());

            $this->newLine();
            $this->table(['Indicador', 'Total'], [
                ['a migrar',                 $vinculos->count()],
                ['com salário',              $vinculos->filter(fn($v) => !empty($v->salary))->count()],
                ['com data de admissão',     $vinculos->filter(fn($v) => !empty($v->admission_date))->count()],
                ['com anotação',             $vinculos->filter(fn($v) => !empty($v->notes) || !empty($v->observations))->count()],
                ['sem candidato no mapa',    $semCand],
                ['sem vaga no mapa',         $semVaga],
                ['com franquia responsável', $vinculos->filter(fn($v) => isset($mapaAutores[$v->consultant_id]))->count()],
            ]);

            if ($semCand || $semVaga) {
                $this->newLine();
                $this->warn('Registros sem correspondência seriam PULADOS.');
            }

            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:migrate-envios</>');
            return 0;
        }

        // ---------- Execução ----------
        $bar = $this->output->createProgressBar($vinculos->count());
        $bar->start();

        $migrados = 0;
        $puladas  = 0;
        $mesclados = 0;
        $comFranquia = 0;

        // Um par candidato+vaga pode repetir depois da deduplicação de
        // candidatos (decisão D): o índice único não permite dois registros.
        $vistos = [];

        foreach ($vinculos as $old) {
            $candidatoId = $mapaCand[$old->candidate_id]  ?? null;
            $vagaId      = $mapaVagas[$old->job_id]       ?? null;

            if (!$candidatoId || !$vagaId) {
                $puladas++;
                $bar->advance();
                continue;
            }

            $chave = "{$candidatoId}-{$vagaId}";
            if (isset($vistos[$chave])) {
                $mesclados++;
                $bar->advance();
                continue;
            }
            $vistos[$chave] = true;

            $status = self::STATUS[$old->status] ?? 'enviado';

            // O currículo ativo do candidato, se houver
            $curriculoId = CandidatoDocumento::where('candidato_id', $candidatoId)
                ->where('ativo', true)->value('id');

            $observacao = trim(implode("\n\n", array_filter([
                $old->notes ?: null,
                $old->observations ?: null,
            ])));

            $franquiaId = $mapaAutores[$old->consultant_id] ?? null;

            $envio = Envio::updateOrCreate(
                ['candidato_id' => $candidatoId, 'vaga_id' => $vagaId],
                [
                    'franquia_id'      => $franquiaId,
                    'curriculo_id'     => $curriculoId,
                    'status'           => $status,
                    'status_empresa'   => self::STATUS_EMPRESA[$old->status] ?? 'pendente',
                    'origem'           => 'migracao',
                    'observacao'       => $observacao ?: null,
                    'salario_aprovado' => $old->salary ?: null,
                    'data_admissao'    => $old->admission_date ?: null,
                ]
            );

            $this->preservarDatas('envios', $envio->id,
                $old->linked_at ?: $old->created_at, $old->updated_at);

            $migrados++;
            if ($franquiaId) $comFranquia++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Concluído: {$migrados} envio(s).");
        $this->table(['Indicador', 'Total'], [
            ['migrados',                     $migrados],
            ['pulados (sem candidato/vaga)', $puladas],
            ['mesclados (par repetido)',     $mesclados],
            ['com franquia responsável',      $comFranquia],
            ['sem franquia (vão para o admin)', $migrados - $comFranquia],
        ]);
        $this->newLine();

        return 0;
    }

    /**
     * ec_users.id do consultor no sistema antigo => franquia_id no novo.
     *
     * A fonte é `candidate_jobs.consultant_id` — quem de fato encaminhou o
     * candidato. NÃO usar `ec_jobs.consultant_ids`, que é lista de acesso à
     * vaga (mediana de 37 consultores por vaga) e não indica responsabilidade.
     */
    private function mapearAutores(): array
    {
        $arquivo = storage_path('app/public/migracao/mapa-franquias.json');

        if (!is_file($arquivo)) {
            $this->error('Mapa de franquias não encontrado. Rode o Passo 1 antes.');
            return [];
        }

        $mapa = json_decode(file_get_contents($arquivo), true) ?: [];

        $porUser = [];
        foreach ($mapa as $entrada) {
            $antigo = (string) ($entrada['user_id_antigo'] ?? '');
            if ($antigo !== '') {
                $porUser[$antigo] = $entrada['franquia_id'];
            }
        }

        return $porUser;
    }

    private function carregarMapa(string $arquivo, string $passo): ?array
    {
        $caminho = storage_path("app/public/migracao/{$arquivo}");
        if (!is_file($caminho)) {
            $this->error("Mapa não encontrado: {$caminho}");
            $this->error("Rode o {$passo} antes.");
            return null;
        }
        return json_decode(file_get_contents($caminho), true) ?: [];
    }
}
