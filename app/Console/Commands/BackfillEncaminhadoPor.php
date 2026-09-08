<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche `envios.encaminhado_por` nos registros anteriores ao módulo
 * multiusuário, atribuindo cada envio ao titular da franquia que o encaminhou.
 *
 * A coluna nasceu vazia: até aqui o sistema só guardava a unidade
 * (`envios.franquia_id`), nunca a pessoa. Sem o backfill, o Relatório de
 * Processos mostraria "—" em quase todo o histórico e o cliente perderia a
 * comparação com o que vier a partir de agora.
 *
 * Decisão do cliente (08/09/2026): atribuir ao titular. É uma suposição — se um
 * assistente tiver feito o encaminhamento antes desta data, ele aparecerá no
 * nome do titular. Como os assistentes só passam a existir com o módulo
 * multiusuário, na prática o titular era o único operador da unidade.
 *
 * Envios sem `franquia_id` (candidatura espontânea pelo feed) não são tocados:
 * não houve operador, e "—" é a informação correta.
 *
 * Idempotente: só mexe em quem ainda está nulo.
 */
class BackfillEncaminhadoPor extends Command
{
    protected $signature = 'ec:backfill-encaminhado-por
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Atribui os envios antigos ao titular de cada franquia';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->newLine();
        $this->info('Backfill de envios.encaminhado_por');
        $this->line('  modo: ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->newLine();

        $semOperador = DB::table('envios')
            ->whereNull('encaminhado_por')
            ->whereNull('franquia_id')
            ->count();

        $pendentes = DB::table('envios')
            ->whereNull('encaminhado_por')
            ->whereNotNull('franquia_id')
            ->count();

        $this->line("  envios sem usuário e sem unidade (não serão tocados): {$semOperador}");
        $this->line("  envios sem usuário, com unidade: {$pendentes}");
        $this->newLine();

        if ($pendentes === 0) {
            $this->info('Nada a fazer.');
            return 0;
        }

        // Titular de cada franquia. A fonte primária é franquias.titular_user_id;
        // quando estiver vazia, cai para o vínculo marcado como titular em
        // franquia_usuarios, e por último para o contexto de acesso mais antigo
        // — que é o do franqueado, criado junto com a unidade.
        $titulares = [];
        $franquias = DB::table('franquias')->select('id', 'nome', 'titular_user_id')->get();

        foreach ($franquias as $f) {
            $titulares[$f->id] = $f->titular_user_id
                ?: DB::table('franquia_usuarios')
                    ->where('franquia_id', $f->id)
                    ->where('tipo', 'titular')
                    ->value('user_id')
                ?: DB::table('user_contexts')
                    ->where('role', 'franquia')
                    ->where('context_id', $f->id)
                    ->orderBy('id')
                    ->value('user_id');
        }

        $linhas = [];
        $totalComTitular = 0;
        $semTitular = [];

        $porFranquia = DB::table('envios')
            ->whereNull('encaminhado_por')
            ->whereNotNull('franquia_id')
            ->selectRaw('franquia_id, COUNT(*) as total')
            ->groupBy('franquia_id')
            ->orderByDesc('total')
            ->get();

        foreach ($porFranquia as $r) {
            $nomeFranquia = $franquias->firstWhere('id', $r->franquia_id)?->nome ?? "#{$r->franquia_id}";
            $userId = $titulares[$r->franquia_id] ?? null;

            if (!$userId) {
                $semTitular[] = [$nomeFranquia, $r->total];
                continue;
            }

            $nomeUser = DB::table('users')->where('id', $userId)->value('name') ?? "#{$userId}";
            $linhas[] = [$nomeFranquia, $nomeUser, $r->total];
            $totalComTitular += $r->total;
        }

        if ($linhas) {
            $this->table(['Unidade', 'Titular', 'Envios'], $linhas);
        }

        if ($semTitular) {
            $this->newLine();
            $this->warn('Unidades sem titular identificável — ficarão como estão:');
            $this->table(['Unidade', 'Envios'], $semTitular);
        }

        $this->newLine();
        $this->line("  a atribuir: {$totalComTitular}");

        if ($dry) {
            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:backfill-encaminhado-por</>');
            return 0;
        }

        $afetados = 0;
        foreach ($titulares as $franquiaId => $userId) {
            if (!$userId) {
                continue;
            }

            $afetados += DB::table('envios')
                ->whereNull('encaminhado_por')
                ->where('franquia_id', $franquiaId)
                ->update(['encaminhado_por' => $userId]);
        }

        $this->newLine();
        $this->info("Concluído: {$afetados} envio(s) atribuído(s).");
        $this->line('Encaminhamentos novos já gravam o usuário real que os fez.');
        $this->newLine();

        return 0;
    }
}
