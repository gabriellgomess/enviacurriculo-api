<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Unifica `pendente` em `enviado` na coluna `envios.status`.
 *
 * Os dois valores descrevem a mesma etapa — currículo entregue, aguardando a
 * empresa — e conviviam por acidente. O diálogo de alterar status da franquia
 * oferecia "Pendente", que gravava `pendente`, enquanto o vínculo nasce como
 * `enviado`. O resultado eram dois nomes para o mesmo estado e um relatório com
 * dois selos diferentes para a mesma coisa.
 *
 * Decisão (24/08/2026): o valor que fica no banco é `enviado`; a interface
 * inteira passa a chamá-lo de "Pendente".
 *
 * Depois deste comando, `Envio::statusFranquiaPara` e a validação do endpoint
 * impedem que `pendente` volte a ser gravado.
 */
class NormalizarStatusEnvios extends Command
{
    protected $signature = 'ec:normalizar-status-envios
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Converte envios com status "pendente" para "enviado"';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->newLine();
        $this->info('Normalização de status dos envios');
        $this->line('  modo: ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->newLine();

        $porStatus = DB::table('envios')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $this->table(['Situação', 'Total'], $porStatus->map(fn($r) => [$r->status, $r->total])->all());

        $alvo = DB::table('envios')->where('status', 'pendente')->count();

        $this->newLine();
        $this->line("  a converter (pendente → enviado): {$alvo}");

        if ($alvo === 0) {
            $this->newLine();
            $this->info('Nada a fazer.');
            return 0;
        }

        if ($dry) {
            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:normalizar-status-envios</>');
            return 0;
        }

        // status_empresa não muda: do lado da empresa "pendente" é o nome certo
        // desta etapa, e é o vocabulário que aquele painel usa.
        $afetados = DB::table('envios')
            ->where('status', 'pendente')
            ->update(['status' => 'enviado']);

        $this->newLine();
        $this->info("Concluído: {$afetados} envio(s) convertido(s).");
        $this->line('A interface passa a exibir "Pendente" para o valor `enviado`.');
        $this->newLine();

        return 0;
    }
}
