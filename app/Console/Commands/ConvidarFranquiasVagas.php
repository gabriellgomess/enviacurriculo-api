<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Franquia;
use App\Models\Vaga;

/**
 * Convida franquias em lote para as vagas.
 *
 * O convite (`vaga_franquia_compartilhada`) é o que libera "Vincular Candidato"
 * no painel da franquia em vaga que não é dela. Como toda vaga migrada ficou na
 * Unidade Matriz, sem convite nenhuma franquia consegue encaminhar candidato
 * para o acervo antigo — e são mais de mil vagas para convidar uma a uma pela
 * tela do admin.
 *
 * Só acrescenta: quem já estava convidado continua, e a dona da vaga é ignorada
 * (ela não precisa de convite para a própria vaga).
 */
class ConvidarFranquiasVagas extends Command
{
    protected $signature = 'ec:convidar-franquias-vagas
                            {--franquias= : IDs separados por vírgula (padrão: todas as ativas)}
                            {--status= : Só vagas neste status (ex.: publicada). Padrão: todas}
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Convida franquias em lote para as vagas';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Franquias a convidar
        $franquias = Franquia::where('active', true);
        if ($ids = $this->option('franquias')) {
            $lista = array_filter(array_map('intval', explode(',', $ids)));
            $franquias->whereIn('id', $lista);
        }
        $franquias = $franquias->orderBy('id')->get(['id', 'nome']);

        if ($franquias->isEmpty()) {
            $this->error('Nenhuma franquia encontrada.');
            return 1;
        }

        // Vagas alvo
        $vagasQuery = Vaga::query();
        if ($status = $this->option('status')) {
            $vagasQuery->where('status', $status);
        }
        $vagas = $vagasQuery->get(['id', 'franquia_id']);

        $this->newLine();
        $this->info('Convite em lote — franquias x vagas');
        $this->line('  modo:      ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->line('  franquias: ' . $franquias->count());
        $this->line('  vagas:     ' . $vagas->count() . ($status ? " (status = {$status})" : ' (todos os status)'));

        if ($vagas->isEmpty()) {
            $this->warn('Nenhuma vaga encontrada.');
            return 0;
        }

        // Convites que já existem, para não recontar nem regravar
        $existentes = DB::table('vaga_franquia_compartilhada')
            ->whereIn('vaga_id', $vagas->pluck('id'))
            ->get()
            ->groupBy('vaga_id')
            ->map(fn($linhas) => $linhas->pluck('franquia_id')->all());

        $agora = now();
        $novos = [];

        foreach ($vagas as $vaga) {
            $jaTem = $existentes[$vaga->id] ?? [];

            foreach ($franquias as $f) {
                // A dona da vaga não precisa de convite para a própria vaga
                if ((int) $vaga->franquia_id === (int) $f->id) continue;
                if (in_array($f->id, $jaTem)) continue;

                $novos[] = [
                    'vaga_id'     => $vaga->id,
                    'franquia_id' => $f->id,
                    'created_at'  => $agora,
                    'updated_at'  => $agora,
                ];
            }
        }

        $this->line('  convites a criar: ' . count($novos));
        $this->newLine();

        $this->table(['Franquia'], $franquias->map(fn($f) => [$f->nome])->all());

        if (empty($novos)) {
            $this->info('Nada a fazer: todas as franquias já estão convidadas.');
            return 0;
        }

        if ($dry) {
            $this->line('Nada foi gravado. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:convidar-franquias-vagas' .
                ($status ? " --status={$status}" : '') . '</>');
            return 0;
        }

        // Em lotes: um insert único com dezenas de milhares de linhas estoura
        // o limite de placeholders do driver.
        $bar = $this->output->createProgressBar((int) ceil(count($novos) / 500));
        $bar->start();

        foreach (array_chunk($novos, 500) as $lote) {
            DB::table('vaga_franquia_compartilhada')->insert($lote);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Concluído: ' . count($novos) . ' convite(s) criado(s).');
        $this->line('As franquias já conseguem vincular candidatos a essas vagas.');
        $this->newLine();

        return 0;
    }
}
