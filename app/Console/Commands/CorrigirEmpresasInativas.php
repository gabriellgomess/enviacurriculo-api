<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Empresa;
use Illuminate\Support\Str;

/**
 * Correção pós-migração: separa "empresa inativa" de "empresa excluída".
 *
 * O Passo 2 tratou `deleted = 1` e `active = 0` como a mesma coisa e mandou os
 * dois casos para o soft delete. O resultado é que 133 empresas sumiram do
 * admin — e nenhum filtro alcança um registro com `deleted_at`, porque o
 * Eloquent o remove de toda consulta.
 *
 * Aqui as que tinham apenas `active = 0` voltam a existir, com `active = false`.
 * Aparecem no filtro de inativas e o usuário reativa quando quiser. As que
 * tinham `deleted = 1` continuam excluídas.
 *
 * Precisa da conexão com o banco antigo (`mysql_antigo`): é lá que está a
 * distinção entre os dois casos.
 */
class CorrigirEmpresasInativas extends Command
{
    protected $signature = 'ec:corrigir-empresas-inativas
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Restaura como inativas as empresas excluídas apenas por active = 0';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->newLine();
        $this->info('Correção — empresas inativas x excluídas');
        $this->line('  modo: ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));

        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error('Sem conexão com o banco antigo: ' . $e->getMessage());
            $this->error('Este comando precisa das variáveis DB_OLD_* para saber');
            $this->error('quais empresas foram de fato excluídas.');
            return 1;
        }

        // ec_companies.id dos que são exclusão de verdade
        $excluidasAntigas = DB::connection('mysql_antigo')
            ->table('ec_companies')
            ->where('deleted', 1)
            ->pluck('id')
            ->map(fn($id) => 'EM-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT))
            ->all();

        // Empresas migradas hoje com deleted_at. O código é EM-{id_antigo}:
        // empresa criada no sistema novo tem outro padrão e não entra aqui.
        $candidatas = Empresa::onlyTrashed()
            ->where('codigo', 'like', 'EM-%')
            ->whereNotIn('codigo', $excluidasAntigas)
            ->get();

        $this->line('  excluídas de fato no sistema antigo: ' . count($excluidasAntigas));
        $this->line('  a restaurar como inativas: ' . $candidatas->count());
        $this->newLine();

        if ($candidatas->isEmpty()) {
            $this->info('Nada a corrigir.');
            return 0;
        }

        $this->table(
            ['Código', 'Razão social', 'Cidade'],
            $candidatas->take(10)->map(fn($e) => [
                $e->codigo,
                Str::limit($e->razao_social, 40),
                $e->cidade ?: '—',
            ])->all()
        );
        if ($candidatas->count() > 10) {
            $this->line('  ... e mais ' . ($candidatas->count() - 10) . '.');
        }

        if ($dry) {
            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:corrigir-empresas-inativas</>');
            return 0;
        }

        $restauradas = 0;

        foreach ($candidatas as $empresa) {
            DB::transaction(function () use ($empresa, &$restauradas) {
                $empresa->restore();
                // Volta visível, mas inativa: é o estado que o sistema antigo
                // registrava com active = 0.
                $empresa->update(['active' => false]);

                // O login continua bloqueado enquanto a empresa estiver inativa.
                $empresa->user()?->update(['active' => false]);

                $restauradas++;
            });
        }

        $this->newLine();
        $this->info("Concluído: {$restauradas} empresa(s) restaurada(s) como inativa(s).");
        $this->line('Elas aparecem no admin sob o filtro "Inativas". O acesso de');
        $this->line('cada uma só é liberado quando o usuário a ativar.');
        $this->newLine();

        return 0;
    }
}
