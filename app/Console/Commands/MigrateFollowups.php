<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\CandidatoFollowup;
use App\Models\Franquia;

/**
 * PASSO 7 do plano de migração.
 *
 * `candidate_follow_up_history` → `candidato_followups`. 3.066 registros.
 *
 * Decisões aplicadas:
 *   24 — migrar todos
 *    E — tabela própria (anotação de contato, não é parecer)
 *    H — `consultant_name` não é migrado quando o autor não virou franquia
 *    K — os que apontam para currículo descartado seguem o candidato mantido
 *
 * Apenas 37 dos 3.066 registros têm `changed_by`; o restante guardava só o
 * nome do consultor em texto, por isso `criado_por` é nullable.
 */
class MigrateFollowups extends Command
{
    use \App\Console\Commands\Concerns\PreservaDatas;

    protected $signature = 'ec:migrate-followups
                            {--matriz= : ID da franquia Unidade Matriz (obrigatório)}
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Migra as anotações de acompanhamento de candidatos';

    private const CONSULTORES = [10, 13, 15, 16, 32, 54, 112, 125, 146, 147, 171, 186];

    public function handle(): int
    {
        $matrizId = (int) $this->option('matriz');
        $dry      = (bool) $this->option('dry-run');

        if (!$matrizId || !Franquia::find($matrizId)) {
            $this->error('Informe um --matriz=<ID> válido.');
            return 1;
        }

        $mapaCand = $this->carregarMapa('migracao-mapa-candidatos.json');
        if ($mapaCand === null) return 1;

        $this->newLine();
        $this->info('PASSO 7 — Follow-ups');
        $this->line('  modo:   ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->line("  matriz: {$matrizId}");
        $this->newLine();

        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error('Sem conexão com o banco antigo: ' . $e->getMessage());
            return 1;
        }

        // Nomes de quem virou franquia (decisão H)
        $nomesFranquia = DB::connection('mysql_antigo')
            ->table('ec_consultants')->whereIn('id', self::CONSULTORES)
            ->pluck('name')->map(fn($n) => mb_strtolower(trim($n)))->all();

        $registros = DB::connection('mysql_antigo')
            ->table('candidate_follow_up_history')->orderBy('id')->get();

        $this->line("  follow-ups no banco antigo: {$registros->count()}");

        // Tipos de follow-up do sistema antigo, para dar contexto à anotação
        $tipos = DB::connection('mysql_antigo')->table('follow_ups')->pluck('nome', 'id')->all();

        // ---------- Simulação ----------
        if ($dry) {
            $semCand   = $registros->filter(fn($r) => !isset($mapaCand[$r->curriculum_id]))->count();
            $semTexto  = $registros->filter(fn($r) => empty($r->follow_up_notes) && empty($tipos[$r->follow_up_id] ?? null))->count();
            $comAutor  = $registros->filter(fn($r) => !empty($r->changed_by))->count();

            $this->newLine();
            $this->table(['Indicador', 'Total'], [
                ['a migrar',                   $registros->count() - $semCand - $semTexto],
                ['sem candidato no mapa',      $semCand],
                ['sem anotação nem tipo',      $semTexto],
                ['com autor identificado',     $comAutor],
                ['tipos de follow-up no antigo', count($tipos)],
            ]);

            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line("  <fg=yellow>php artisan ec:migrate-followups --matriz={$matrizId}</>");
            return 0;
        }

        // ---------- Execução ----------
        $bar = $this->output->createProgressBar($registros->count());
        $bar->start();

        $migrados = 0;
        $puladas  = 0;
        $vazios   = 0;

        foreach ($registros as $old) {
            $candidatoId = $mapaCand[$old->curriculum_id] ?? null;
            if (!$candidatoId) {
                $puladas++;
                $bar->advance();
                continue;
            }

            // Monta a anotação: tipo + notas + autor (só se virou franquia)
            $partes = [];
            if (!empty($tipos[$old->follow_up_id])) {
                $partes[] = '[' . $tipos[$old->follow_up_id] . ']';
            }
            if (!empty($old->follow_up_notes)) {
                $partes[] = $old->follow_up_notes;
            }

            $nome = mb_strtolower(trim((string) $old->consultant_name));
            if ($nome !== '' && in_array($nome, $nomesFranquia, true)) {
                $partes[] = '— ' . $old->consultant_name;
            }

            $anotacao = trim(implode(' ', $partes));
            if ($anotacao === '') {
                $vazios++;
                $bar->advance();
                continue;
            }

            $fu = CandidatoFollowup::updateOrCreate(
                [
                    'candidato_id' => $candidatoId,
                    'anotacao'     => $anotacao,
                    'data_contato' => $old->follow_up_date ?: null,
                ],
                [
                    'franquia_id' => $matrizId,
                    'criado_por'  => null,
                ]
            );

            $this->preservarDatas('candidato_followups', $fu->id,
                $old->created_at, $old->updated_at);

            $migrados++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Concluído: {$migrados} follow-up(s).");
        $this->table(['Indicador', 'Total'], [
            ['migrados',                  $migrados],
            ['pulados (sem candidato)',   $puladas],
            ['pulados (sem conteúdo)',    $vazios],
        ]);
        $this->newLine();

        return 0;
    }

    private function carregarMapa(string $arquivo): ?array
    {
        $caminho = storage_path("app/{$arquivo}");
        if (!is_file($caminho)) {
            $this->error("Mapa não encontrado: {$caminho}");
            $this->error('Rode o Passo 4 (ec:migrate-candidates) antes.');
            return null;
        }
        return json_decode(file_get_contents($caminho), true) ?: [];
    }
}
