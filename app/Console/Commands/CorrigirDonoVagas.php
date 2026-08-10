<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Franquia;

/**
 * Correção pós-migração: devolve TODAS as vagas migradas à Unidade Matriz.
 *
 * O Passo 3 chegou a deduzir o dono da vaga pelo consultor que mais encaminhou
 * candidatos para ela. A regra do negócio é outra: só franquia premium é dona
 * de vaga, e é dona porque é dona da empresa. Nenhum dos 12 consultores
 * migrados é premium, e todas as empresas entraram na Matriz.
 *
 * Sintoma corrigido: em Status Candidatos a franquia via encaminhamentos de
 * outras franquias — e podia alterar o status deles —, porque a visibilidade
 * inclui "envios nas vagas que são minhas".
 *
 * Só toca nas vagas listadas em mapa-vagas.json: vaga criada no sistema novo
 * por franquia premium mantém o dono.
 */
class CorrigirDonoVagas extends Command
{
    protected $signature = 'ec:corrigir-dono-vagas
                            {--matriz= : ID da franquia Unidade Matriz (obrigatório)}
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Devolve as vagas migradas à Unidade Matriz';

    public function handle(): int
    {
        $matrizId = (int) $this->option('matriz');
        $dry      = (bool) $this->option('dry-run');

        if (!$matrizId || !Franquia::find($matrizId)) {
            $this->error('Informe um --matriz=<ID> válido.');
            return 1;
        }

        $arquivo = storage_path('app/public/migracao/mapa-vagas.json');
        if (!is_file($arquivo)) {
            $this->error('Mapa de vagas não encontrado em:');
            $this->error("  {$arquivo}");
            return 1;
        }

        // [id_antigo => id_novo]
        $ids = array_values(json_decode(file_get_contents($arquivo), true) ?: []);

        $this->newLine();
        $this->info('Correção — dono das vagas migradas');
        $this->line('  modo:   ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->line("  matriz: {$matrizId}");
        $this->line('  vagas migradas: ' . count($ids));

        if (empty($ids)) {
            $this->warn('Mapa vazio, nada a fazer.');
            return 0;
        }

        // Quem perde vagas — vale conferir antes de gravar
        $fora = DB::table('vagas')
            ->whereIn('id', $ids)
            ->where(fn($q) => $q->where('franquia_id', '!=', $matrizId)->orWhereNull('franquia_id'))
            ->select('franquia_id', DB::raw('COUNT(*) as total'))
            ->groupBy('franquia_id')
            ->get();

        $total = $fora->sum('total');
        $this->line("  vagas fora da Matriz: {$total}");
        $this->newLine();

        if ($total === 0) {
            $this->info('Nada a corrigir.');
            return 0;
        }

        $nomes = Franquia::whereIn('id', $fora->pluck('franquia_id')->filter())
            ->pluck('nome', 'id');

        $this->table(['franquia_id', 'Franquia', 'Vagas'], $fora->map(fn($f) => [
            $f->franquia_id ?? '—',
            $f->franquia_id ? ($nomes[$f->franquia_id] ?? '?') : '(sem franquia)',
            $f->total,
        ])->all());

        if ($dry) {
            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line("  <fg=yellow>php artisan ec:corrigir-dono-vagas --matriz={$matrizId}</>");
            return 0;
        }

        // Em lotes: a lista de ids vai para uma cláusula IN.
        $afetadas = 0;
        foreach (array_chunk($ids, 500) as $lote) {
            $afetadas += DB::table('vagas')
                ->whereIn('id', $lote)
                ->where(fn($q) => $q->where('franquia_id', '!=', $matrizId)->orWhereNull('franquia_id'))
                ->update(['franquia_id' => $matrizId]);
        }

        $this->newLine();
        $this->info("Concluído: {$afetadas} vaga(s) devolvida(s) à Matriz.");
        $this->line('Os encaminhamentos não mudam: a responsabilidade de cada um');
        $this->line('continua em envios.franquia_id.');
        $this->newLine();

        return 0;
    }
}
