<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche o histórico de status com as aprovações que aconteceram antes de
 * o histórico existir. Não há registro da data real em lugar nenhum, então
 * as linhas entram com estimado = true:
 *
 *  - Aprovado: a data da última alteração do vínculo. Conferido com os dados
 *    de produção (out/2026): bate com o mês de aprovação em 191 de 194
 *    contratações do sistema novo.
 *  - Reposição: a última alteração é quando virou reposição, não quando foi
 *    aprovado. A aprovação fica na data de admissão (ou na última alteração,
 *    se for anterior ou se não houver admissão), e a reposição na última
 *    alteração.
 *
 * Idempotente: vínculo que já tem linha "aprovado" no histórico é ignorado.
 * Não altera a tabela envios.
 */
class HistoricoStatusInicial extends Command
{
    protected $signature = 'envios:historico-inicial {--dry-run : Mostra o que seria gravado, sem gravar}';

    protected $description = 'Preenche o histórico de status com as aprovações anteriores ao histórico (datas estimadas)';

    public function handle(): int
    {
        $simular = (bool) $this->option('dry-run');

        $pendentes = DB::table('envios as e')
            ->whereIn('e.status', ['aprovado', 'reposicao'])
            ->whereNotExists(fn($q) => $q->from('envio_status_historico as h')
                ->whereColumn('h.envio_id', 'e.id')->where('h.status_novo', 'aprovado'))
            ->select('e.id', 'e.status', 'e.data_admissao', 'e.updated_at', 'e.origem');

        $total = (clone $pendentes)->count();
        if ($total === 0) {
            $this->info('Nada a fazer: todas as contratações já têm a aprovação no histórico.');
            return self::SUCCESS;
        }

        $this->info(($simular ? '[simulação] ' : '') . "{$total} contratação(ões) sem data de aprovação no histórico.");

        $porMes = [];
        $gravadas = 0;
        $agora = now();

        $pendentes->orderBy('e.id')->chunk(500, function ($lote) use ($simular, &$porMes, &$gravadas, $agora) {
            $linhas = [];
            foreach ($lote as $e) {
                $alteracao = Carbon::parse($e->updated_at);
                $aprovadoEm = $alteracao;

                if ($e->status === 'reposicao' && $e->data_admissao) {
                    $aprovadoEm = Carbon::parse($e->data_admissao)->min($alteracao);
                }

                $linhas[] = $this->linha($e->id, null, 'aprovado', $aprovadoEm, $agora);
                if ($e->status === 'reposicao') {
                    $linhas[] = $this->linha($e->id, 'aprovado', 'reposicao', $alteracao, $agora);
                }

                $chave = $aprovadoEm->format('Y-m') . ' (' . ($e->origem ?: 'sem origem') . ')';
                $porMes[$chave] = ($porMes[$chave] ?? 0) + 1;
            }

            if (!$simular) {
                DB::table('envio_status_historico')->insert($linhas);
            }
            $gravadas += count($linhas);
        });

        ksort($porMes);
        $this->table(['Mês da aprovação (origem)', 'Contratações'], collect($porMes)->map(fn($n, $k) => [$k, $n])->values()->all());
        $this->info(($simular ? '[simulação] Seriam gravadas ' : 'Gravadas ') . "{$gravadas} linha(s) de histórico, marcadas como estimadas.");

        return self::SUCCESS;
    }

    private function linha(int $envioId, ?string $anterior, string $novo, Carbon $quando, Carbon $agora): array
    {
        return [
            'envio_id'        => $envioId,
            'status_anterior' => $anterior,
            'status_novo'     => $novo,
            'user_id'         => null,
            'painel'          => null,
            'estimado'        => true,
            'ocorrido_em'     => $quando,
            'created_at'      => $agora,
        ];
    }
}
