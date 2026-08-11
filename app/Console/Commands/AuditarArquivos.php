<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Confere quais registros apontam para arquivos que não existem no storage.
 *
 * Depois da migração ficaram registros sem o arquivo correspondente: o dump do
 * banco e o retrato da pasta foram tirados em momentos diferentes, então tudo
 * que entrou nesse intervalo tem linha no banco e nada no disco. O sintoma é
 * 404 ao baixar o currículo.
 *
 * Só lê — não apaga nem altera nada. Gera um CSV para conferência.
 */
class AuditarArquivos extends Command
{
    protected $signature = 'ec:auditar-arquivos
                            {--tipo=todos : curriculos, logos ou todos}
                            {--csv : Grava a lista dos faltantes em CSV}';

    protected $description = 'Lista registros cujo arquivo não existe no storage';

    public function handle(): int
    {
        $tipo = $this->option('tipo');
        $disk = Storage::disk('public');

        $this->newLine();
        $this->info('Auditoria de arquivos');
        $this->line('  disco: ' . config('filesystems.disks.public.root'));
        $this->newLine();

        $faltantes = [];
        $resumo    = [];

        if (in_array($tipo, ['todos', 'curriculos'], true)) {
            $resumo[] = $this->checar('candidato_documentos', 'Documentos de candidato',
                $disk, $faltantes);
        }

        if (in_array($tipo, ['todos', 'logos'], true)) {
            $resumo[] = $this->checar('empresas', 'Logotipos de empresa',
                $disk, $faltantes, 'logo_url');
        }

        // Banners de vaga ficam de fora: não há coluna no banco apontando para
        // eles. O arquivo vive só no disco, em vagas/{id}/banner/, então não
        // existe registro para conferir contra.

        $this->newLine();
        $this->table(['Conjunto', 'Registros', 'Com arquivo', 'Sem arquivo'], $resumo);

        if (empty($faltantes)) {
            $this->newLine();
            $this->info('Nenhum registro sem arquivo. Nada a fazer.');
            return 0;
        }

        if ($this->option('csv')) {
            @mkdir(storage_path('app/public/migracao'), 0775, true);
            $arquivo = storage_path('app/public/migracao/arquivos-faltantes.csv');

            $fp = fopen($arquivo, 'w');
            fputcsv($fp, ['conjunto', 'id', 'referencia', 'nome', 'caminho']);
            foreach ($faltantes as $linha) fputcsv($fp, $linha);
            fclose($fp);

            $this->newLine();
            $this->info('CSV gravado em:');
            $this->line("  {$arquivo}");
            $this->warn('Este caminho é público pela web — apague o arquivo depois de usar.');
        } else {
            $this->newLine();
            $this->line('Para gerar a lista completa:');
            $this->line('  <fg=yellow>php artisan ec:auditar-arquivos --csv</>');
        }

        $this->newLine();
        return 0;
    }

    private function checar(
        string $tabela,
        string $rotulo,
        $disk,
        array &$faltantes,
        string $coluna = 'arquivo_path'
    ): array {
        $temSoftDelete = in_array($tabela, ['empresas', 'vagas'], true);

        $query = DB::table($tabela)->whereNotNull($coluna)->where($coluna, '!=', '');
        if ($temSoftDelete) $query->whereNull('deleted_at');

        $total = (clone $query)->count();
        $semArquivo = 0;

        $bar = $this->output->createProgressBar($total);
        $this->line("  {$rotulo}: {$total} registro(s)");
        $bar->start();

        $query->orderBy('id')->chunk(500, function ($linhas) use (
            $disk, $coluna, $tabela, $rotulo, &$faltantes, &$semArquivo, $bar
        ) {
            foreach ($linhas as $linha) {
                $caminho = $this->normalizar($linha->{$coluna});

                if ($caminho !== '' && !$disk->exists($caminho)) {
                    $semArquivo++;
                    $faltantes[] = [
                        $rotulo,
                        $linha->id,
                        // referência útil para achar o dono do registro
                        $linha->candidato_id ?? $linha->empresa_id ?? $linha->codigo ?? '—',
                        $linha->arquivo_nome ?? $linha->razao_social ?? $linha->titulo ?? '—',
                        $caminho,
                    ];
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        return [$rotulo, $total, $total - $semArquivo, $semArquivo];
    }

    /**
     * Alguns registros guardam URL completa em vez do caminho relativo
     * (o campo `logo_url` recebeu `Storage::url()` em parte dos fluxos).
     */
    private function normalizar(?string $valor): string
    {
        $valor = trim((string) $valor);
        if ($valor === '') return '';

        if (str_starts_with($valor, 'http')) {
            $valor = parse_url($valor, PHP_URL_PATH) ?: $valor;
        }

        return ltrim(preg_replace('#^/?storage/#', '', $valor), '/');
    }
}
