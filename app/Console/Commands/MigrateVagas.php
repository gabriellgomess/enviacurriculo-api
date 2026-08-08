<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Vaga;
use App\Models\Franquia;

/**
 * PASSO 3 do plano de migração.
 *
 * `ec_jobs` → `vagas`. 1.332 registros.
 *
 * Decisões aplicadas:
 *   13 — `cargo` vira o título; a área (`job_title`) vai para observações
 *   14 — ACTIVE → publicada (227); demais → fechada (1.105)
 *   15/G — deleted = 1 → pausada, SEM deleted_at (100 vagas reativáveis)
 *   16 — banners copiados de JobBanner/ quando o arquivo existir
 *    7 — franquia_id = Unidade Matriz
 *
 * Depende do mapa gerado pelo Passo 2 (storage/app/migracao-mapa-empresas.json),
 * inclusive para reapontar as vagas das empresas mescladas por CNPJ.
 */
class MigrateVagas extends Command
{
    use \App\Console\Commands\Concerns\PreservaDatas;

    protected $signature = 'ec:migrate-vagas
                            {--matriz= : ID da franquia Unidade Matriz (obrigatório)}
                            {--path= : Caminho da pasta storage/app do sistema antigo}
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Migra as vagas do sistema antigo';

    /** ec_consultants.id dos que viraram franquia (decisão O). */
    private const CONSULTORES = [10, 13, 15, 16, 32, 54, 112, 125, 146, 147, 171, 186];

    /**
     * ec_consultants.id → franquia_id no sistema novo.
     *
     * `ec_jobs.consultant_ids` guarda quem atendeu a vaga. Sem essa atribuição
     * o franqueado entra e não vê nem as vagas nem o histórico de vinculações,
     * porque a visibilidade em FranquiaCandidatoController parte das vagas
     * da franquia.
     */
    private function mapearConsultores(): array
    {
        $antigos = DB::connection('mysql_antigo')
            ->table('ec_consultants')
            ->whereIn('id', self::CONSULTORES)
            ->get(['id', 'email']);

        $mapa = [];
        foreach ($antigos as $c) {
            $f = Franquia::where('email', $c->email)->first();
            if ($f) $mapa[(string) $c->id] = $f->id;
        }

        return $mapa;
    }

    /** Franquia responsável pela vaga, ou a Matriz se não houver atribuição. */
    private function franquiaDaVaga($old, array $mapaConsultores, int $matrizId): int
    {
        $ids = json_decode($old->consultant_ids ?? '[]', true);
        if (!is_array($ids)) return $matrizId;

        foreach ($ids as $id) {
            $chave = (string) $id;
            if (isset($mapaConsultores[$chave])) {
                return $mapaConsultores[$chave];
            }
        }

        return $matrizId;
    }

    public function handle(): int
    {
        $matrizId = (int) $this->option('matriz');
        $path     = $this->option('path');
        $dry      = (bool) $this->option('dry-run');

        if (!$matrizId || !Franquia::find($matrizId)) {
            $this->error('Informe um --matriz=<ID> válido.');
            return 1;
        }

        // Mapa do Passo 2
        $arquivoMapa = storage_path('app/public/migracao/mapa-empresas.json');
        if (!is_file($arquivoMapa)) {
            $this->error('Mapa de empresas não encontrado em:');
            $this->error("  {$arquivoMapa}");
            $this->error('Rode o Passo 2 (ec:migrate-empresas) antes.');
            return 1;
        }
        $mapaEmpresas = json_decode(file_get_contents($arquivoMapa), true) ?: [];

        $this->newLine();
        $this->info('PASSO 3 — Vagas');
        $this->line('  modo:     ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->line("  matriz:   {$matrizId}");
        $this->line('  banners:  ' . ($path ? $path . '/JobBanner/' : 'não informado — banners não serão copiados'));
        $this->line('  empresas no mapa: ' . count($mapaEmpresas));
        $this->newLine();

        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error('Sem conexão com o banco antigo: ' . $e->getMessage());
            return 1;
        }

        $vagas = DB::connection('mysql_antigo')->table('ec_jobs')->orderBy('id')->get();
        $this->line("  vagas no banco antigo: {$vagas->count()}");

        $mapaConsultores = $this->mapearConsultores();
        $this->line('  franquias mapeadas por consultant_ids: ' . count($mapaConsultores));

        // ---------- Simulação ----------
        if ($dry) {
            $semEmpresa = $vagas->filter(fn($v) => empty($mapaEmpresas[$v->company_id]))->count();
            $porStatus  = $vagas->groupBy(fn($v) => $this->mapStatus($v))->map->count();
            $comBanner  = $vagas->filter(fn($v) => !empty($v->banner_storage_location))->count();
            $naMatriz   = $vagas->filter(fn($v) =>
                $this->franquiaDaVaga($v, $mapaConsultores, $matrizId) === $matrizId)->count();

            $this->newLine();
            $this->table(['Situação no sistema novo', 'Total'],
                $porStatus->map(fn($q, $s) => [$s, $q])->values()->all());

            $this->newLine();
            $this->table(['Indicador', 'Total'], [
                ['a migrar',                   $vagas->count()],
                ['atribuídas às 12 franquias', $vagas->count() - $naMatriz],
                ['ficam na Unidade Matriz',    $naMatriz],
                ['com banner no banco',        $comBanner],
                ['sem empresa correspondente', $semEmpresa],
            ]);

            if ($semEmpresa > 0) {
                $this->newLine();
                $this->warn("{$semEmpresa} vaga(s) apontam para empresa fora do mapa e seriam PULADAS.");
            }

            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line("  <fg=yellow>php artisan ec:migrate-vagas --matriz={$matrizId}</>");
            return 0;
        }

        // ---------- Execução ----------
        $bar = $this->output->createProgressBar($vagas->count());
        $bar->start();

        $mapa      = [];
        $migradas  = 0;
        $puladas   = 0;
        $comBanner = 0;
        $naMatriz  = 0;
        $contagem  = ['publicada' => 0, 'fechada' => 0, 'pausada' => 0];

        foreach ($vagas as $old) {
            $empresaId = $mapaEmpresas[$old->company_id] ?? null;

            if (!$empresaId) {
                $puladas++;
                $bar->advance();
                continue;
            }

            $franquiaVaga = $this->franquiaDaVaga($old, $mapaConsultores, $matrizId);

            $resultado = DB::transaction(function () use ($old, $empresaId, $franquiaVaga, $matrizId, $path) {
                $status = $this->mapStatus($old);

                // Requisitantes vêm em JSON: ["nome","email"]
                $req = json_decode($old->requisitantes ?? '[]', true);
                $primeiro = is_array($req) && !empty($req) ? $req[0] : [];

                $vaga = Vaga::updateOrCreate(
                    ['codigo' => 'VG-' . str_pad((string) $old->id, 5, '0', STR_PAD_LEFT)],
                    [
                        'titulo'            => $this->mapTitulo($old),        // decisão 13
                        'descricao'         => $old->description,
                        'requisitos'        => $old->requisitos,
                        'beneficios'        => $old->beneficios,
                        'observacoes'       => $this->mapObservacoes($old),
                        'empresa_id'        => $empresaId,
                        'franquia_id'       => $franquiaVaga,
                        'tipo_contrato'     => $this->mapTipoContrato($old->tipo_contratacao),
                        'regime_trabalho'   => 'presencial',
                        'horario_trabalho'  => $this->limitar($old->horario_trabalho, 50),
                        'turno'             => $this->limitar($old->turno, 20),
                        'genero'            => $this->limitar($old->preferencia_genero, 20),
                        'salario_min'       => $old->salario,
                        'exibir_salario'    => !empty($old->salario),
                        'cep'               => $this->mapCep($old->cep),
                        'logradouro'        => $old->street,
                        'numero'            => $old->number,
                        'bairro'            => $old->neighborhood,
                        'cidade'            => $old->city,
                        'estado'            => $this->mapEstado($old->state),
                        'quantidade_vagas'  => max(1, (int) $old->positions),
                        'status'            => $status,
                        'canal'             => $this->mapCanal($old->distribution_channel),
                        'nome_requisitante' => $primeiro['nome']  ?? null,
                        'email_requisitante'=> $primeiro['email'] ?? null,
                        'requisitantes'     => $old->requisitantes,
                        'data_abertura'     => $old->created_date ? substr($old->created_date, 0, 10) : null,
                        'data_fechamento'   => $old->closed_date  ? substr($old->closed_date, 0, 10)  : null,
                    ]
                );

                $banner = false;
                if ($path && !empty($old->banner_storage_location)) {
                    $origem = rtrim($path, '/') . '/' . ltrim($old->banner_storage_location, '/');
                    if (is_file($origem)) {
                        $nome = basename($origem);
                        // Alguns nomes terminam em ponto — normaliza para não quebrar a URL
                        $nome = rtrim($nome, '.') ?: 'banner';
                        $destino = "vagas/{$vaga->id}/banner/{$nome}";
                        Storage::disk('public')->put($destino, file_get_contents($origem));
                        $banner = true;
                    }
                }

                $this->preservarDatas('vagas', $vaga->id,
                    $old->created_date ?: $old->created_at, $old->updated_at);

                return compact('vaga', 'status', 'banner', 'franquiaVaga');
            });

            $mapa[$old->id] = $resultado['vaga']->id;
            $contagem[$resultado['status']] = ($contagem[$resultado['status']] ?? 0) + 1;
            if ($resultado['banner']) $comBanner++;
            if ($resultado['franquiaVaga'] === $matrizId) $naMatriz++;
            $migradas++;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Concluído: {$migradas} vaga(s) migrada(s).");
        $this->table(['Indicador', 'Total'], [
            ['publicadas',        $contagem['publicada'] ?? 0],
            ['fechadas',          $contagem['fechada']   ?? 0],
            ['pausadas (excluídas no antigo)', $contagem['pausada'] ?? 0],
            ['atribuídas às 12 franquias', $migradas - $naMatriz],
            ['ficam na Unidade Matriz',    $naMatriz],
            ['com banner',        $comBanner],
            ['puladas (sem empresa)', $puladas],
        ]);

        @mkdir(storage_path('app/public/migracao'), 0775, true);
        $arquivo = storage_path('app/public/migracao/mapa-vagas.json');
        file_put_contents($arquivo, json_encode($mapa, JSON_PRETTY_PRINT));
        $this->newLine();
        $this->info('Mapa id_antigo → vaga_id salvo em:');
        $this->line("  {$arquivo}");
        $this->line('  (o Passo 6 usa este arquivo para vincular os envios)');
        $this->newLine();

        return 0;
    }

    /** Decisão 14 e 15: excluída vira pausada; ACTIVE vira publicada; resto fecha. */
    private function mapStatus($old): string
    {
        if ((int) $old->deleted === 1) return 'pausada';
        return strtoupper((string) $old->status) === 'ACTIVE' ? 'publicada' : 'fechada';
    }

    /** Decisão 13: o cargo é o título; sem cargo, cai para a área. */
    private function mapTitulo($old): string
    {
        $cargo = trim((string) $old->cargo);
        if ($cargo !== '') return mb_substr($cargo, 0, 255);

        $area = trim((string) $old->job_title);
        return $area !== '' ? mb_substr($area, 0, 255) : 'Vaga sem título';
    }

    /** A área e as observações gerais do sistema antigo. */
    private function mapObservacoes($old): ?string
    {
        $partes = [];
        if (!empty($old->job_title))          $partes[] = 'Área: ' . $old->job_title;
        if (!empty($old->observacoes_gerais)) $partes[] = $old->observacoes_gerais;

        return $partes ? implode("\n\n", $partes) : null;
    }

    private function mapTipoContrato(?string $tipo): string
    {
        $t = strtolower(trim((string) $tipo));
        return in_array($t, ['clt', 'pj', 'estagio', 'temporario', 'freelancer'], true) ? $t : 'outros';
    }

    private function mapCanal(?string $canal): string
    {
        return match (strtolower((string) $canal)) {
            'agency' => 'agencia',
            'both'   => 'ambos',
            default  => 'plataforma',
        };
    }

    private function limitar(?string $v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function mapCep(?string $cep): ?string
    {
        if (empty($cep)) return null;
        $d = preg_replace('/\D/', '', $cep);
        return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : substr($d, 0, 9);
    }

    private function mapEstado(?string $state): ?string
    {
        if (empty($state)) return null;
        $state = trim(mb_strtoupper($state, 'UTF-8'));
        if (mb_strlen($state) === 2) return $state;

        $mapa = [
            'ACRE' => 'AC', 'ALAGOAS' => 'AL', 'AMAPA' => 'AP', 'AMAZONAS' => 'AM',
            'BAHIA' => 'BA', 'CEARA' => 'CE', 'DISTRITO FEDERAL' => 'DF',
            'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO', 'MARANHAO' => 'MA',
            'MATO GROSSO' => 'MT', 'MATO GROSSO DO SUL' => 'MS', 'MINAS GERAIS' => 'MG',
            'PARA' => 'PA', 'PARAIBA' => 'PB', 'PARANA' => 'PR', 'PERNAMBUCO' => 'PE',
            'PIAUI' => 'PI', 'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN',
            'RIO GRANDE DO SUL' => 'RS', 'RONDONIA' => 'RO', 'RORAIMA' => 'RR',
            'SANTA CATARINA' => 'SC', 'SAO PAULO' => 'SP', 'SERGIPE' => 'SE',
            'TOCANTINS' => 'TO',
        ];

        $normal = iconv('UTF-8', 'ASCII//TRANSLIT', $state);
        $normal = trim(preg_replace('/[^A-Z\s]/', '', mb_strtoupper($normal)));

        return $mapa[$normal] ?? mb_substr($state, 0, 2);
    }
}
