<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Empresa;
use App\Models\Franquia;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserContext;

/**
 * PASSO 2 do plano de migração.
 *
 * `ec_companies` → `empresas`. 226 registros no dump, 217 após mesclar os
 * CNPJs duplicados.
 *
 * Decisões aplicadas:
 *   6 — tipo_acesso = 'agencia' para todas
 *   7 — franquia_id = Unidade Matriz
 *   8 — logotipos copiados de Company/ (119 arquivos)
 *   9 — CNPJ duplicado: mescla, preferindo o registro visível
 *  10 — empresas sem user_id ganham acesso com senha aleatória
 *  11 — plano = NULL
 *  12/P — deleted = 1 (13) ou active = 0 (133) entram com deleted_at
 *
 * O mapa id_antigo → empresa_id é impresso ao final: o Passo 3 (vagas) precisa
 * dele, inclusive para reapontar as vagas dos cadastros mesclados.
 */
class MigrateEmpresas extends Command
{
    use \App\Console\Commands\Concerns\PreservaDatas;

    protected $signature = 'ec:migrate-empresas
                            {--matriz= : ID da franquia Unidade Matriz (obrigatório)}
                            {--path= : Caminho da pasta storage/app do sistema antigo}
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Migra as empresas do sistema antigo';

    public function handle(): int
    {
        $matrizId = (int) $this->option('matriz');
        $path     = $this->option('path');
        $dry      = (bool) $this->option('dry-run');

        if (!$matrizId) {
            $this->error('Informe --matriz=<ID> (rode ec:criar-unidade-matriz antes).');
            return 1;
        }

        $matriz = Franquia::find($matrizId);
        if (!$matriz) {
            $this->error("Franquia {$matrizId} não encontrada.");
            return 1;
        }

        $this->newLine();
        $this->info('PASSO 2 — Empresas');
        $this->line('  modo:   ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->line("  matriz: {$matriz->nome} (ID {$matriz->id})");
        $this->line('  logos:  ' . ($path ? $path . '/Company/' : 'não informado — logos não serão copiados'));
        $this->newLine();

        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error("Sem conexão com o banco antigo: " . $e->getMessage());
            return 1;
        }

        $todas = DB::connection('mysql_antigo')->table('ec_companies')->orderBy('id')->get();
        $this->line("  registros no banco antigo: {$todas->count()}");

        // ---------- Deduplicação por CNPJ normalizado (decisão 9) ----------
        [$selecionadas, $descartadas] = $this->deduplicar($todas);

        $this->line("  após mesclar CNPJ duplicado: {$selecionadas->count()}");
        if ($descartadas->isNotEmpty()) {
            $this->line("  descartados na mescla: {$descartadas->count()}");
        }
        $this->newLine();

        if ($dry) {
            $this->mostrarSimulacao($selecionadas, $descartadas);
            return 0;
        }

        // ---------- Execução ----------
        $bar = $this->output->createProgressBar($selecionadas->count());
        $bar->start();

        $mapa        = [];   // id_antigo => empresa_id
        $criadas     = 0;
        $ocultas     = 0;
        $comLogo     = 0;
        $usuarios    = 0;
        $semCnpj     = [];
        $semAcesso   = [];

        foreach ($selecionadas as $old) {
            $resultado = DB::transaction(function () use ($old, $matrizId, $path, &$semCnpj) {
                $cnpj = $this->mapCnpj($old->cnpj);
                if ($cnpj === null && !empty($old->cnpj)) {
                    $semCnpj[] = "{$old->id} — {$old->name} ({$old->cnpj})";
                }

                $oculta = ((int) $old->deleted === 1) || ((int) $old->active === 0);

                $empresa = Empresa::withTrashed()->updateOrCreate(
                    ['codigo' => 'EM-' . str_pad((string) $old->id, 5, '0', STR_PAD_LEFT)],
                    [
                        'razao_social'   => $old->name,
                        'nome_fantasia'  => $old->name,
                        'cnpj'           => $cnpj,
                        'email'          => $old->email,
                        'telefone'       => $this->mapTelefone($old->phone),
                        'tipo_empresa'   => $old->company_type === 'branch-office' ? 'filial' : 'matriz',
                        'tipo_acesso'    => 'agencia',      // decisão 6
                        'plano'          => null,           // decisão 11
                        'status'         => 'aprovado',
                        'descricao'      => $old->description,
                        'cep'            => $this->mapCep($old->cep),
                        'rua'            => $old->street,
                        'numero'         => $old->number,
                        'complemento'    => $old->complement,
                        'bairro'         => $old->neighborhood,
                        'cidade'         => $old->city,
                        'estado'         => $this->mapEstado($old->state),
                        'latitude'       => $old->location_lat,
                        'longitude'      => $old->location_lng,
                        'franquia_id'    => $matrizId,      // decisão 7
                        'active'         => !$oculta,
                    ]
                );

                // Decisão 12/P — oculta preservando o registro
                if ($oculta && !$empresa->trashed()) {
                    $empresa->delete();
                } elseif (!$oculta && $empresa->trashed()) {
                    $empresa->restore();
                }

                // Decisão 8 — logotipo
                $logo = false;
                if ($path && !empty($old->logo_storage_location)) {
                    $origem = rtrim($path, '/') . '/' . ltrim($old->logo_storage_location, '/');
                    if (is_file($origem)) {
                        $destino = "empresas/{$empresa->id}/logo/" . basename($origem);
                        Storage::disk('public')->put($destino, file_get_contents($origem));
                        $empresa->update(['logo_url' => $destino]);
                        $logo = true;
                    }
                }

                // Decisão 10 — acesso
                $novoUsuario = false;
                $colisaoAdmin = false;

                if (!empty($old->email)) {
                    $user = User::withTrashed()->where('email', $old->email)->first();

                    // Não contaminar conta admin com papel de empresa:
                    // 3 empresas do sistema antigo usam e-mail de administrador.
                    if ($user && UserRole::where('user_id', $user->id)->where('role', 'admin')->exists()) {
                        $colisaoAdmin = true;
                    } else {
                        if (!$user) {
                            $user = User::create([
                                'name'     => $old->name,
                                'email'    => $old->email,
                                'phone'    => $this->mapTelefone($old->phone),
                                'password' => Hash::make(Str::random(24)),
                                'active'   => !$oculta,
                            ]);
                            $novoUsuario = true;
                        }

                        UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'empresa']);
                        UserContext::updateOrCreate(
                            ['user_id' => $user->id, 'role' => 'empresa'],
                            ['context_id' => $empresa->id]
                        );
                    }
                }

                $this->preservarDatas('empresas', $empresa->id,
                    $old->created_at ?: $old->created_date, $old->updated_at);

                return compact('empresa', 'oculta', 'logo', 'novoUsuario', 'colisaoAdmin');
            });

            $mapa[$old->id] = $resultado['empresa']->id;
            $criadas++;
            if ($resultado['oculta'])      $ocultas++;
            if ($resultado['logo'])        $comLogo++;
            if ($resultado['novoUsuario']) $usuarios++;
            if ($resultado['colisaoAdmin']) {
                $semAcesso[] = "{$old->id} — {$old->name} ({$old->email})";
            }

            $bar->advance();
        }

        // Descartados na mescla apontam para o registro vencedor
        foreach ($descartadas as $old => $vencedor) {
            $mapa[$old] = $mapa[$vencedor] ?? null;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Concluído: {$criadas} empresa(s) migrada(s).");
        $this->table(['Indicador', 'Total'], [
            ['visíveis',            $criadas - $ocultas],
            ['ocultas (deleted_at)', $ocultas],
            ['com logotipo',        $comLogo],
            ['usuários criados',    $usuarios],
            ['mescladas por CNPJ',  count($descartadas)],
        ]);

        if ($semCnpj) {
            $this->newLine();
            $this->warn('Empresas migradas SEM CNPJ (valor original inválido):');
            foreach ($semCnpj as $l) $this->line("  · {$l}");
        }

        if ($semAcesso) {
            $this->newLine();
            $this->warn('Empresas SEM acesso — o e-mail pertence a uma conta admin:');
            foreach ($semAcesso as $l) $this->line("  · {$l}");
            $this->line('  (crie um login próprio pelo painel se essas empresas forem usar o sistema)');
        }

        // Mapa para o Passo 3
        @mkdir(storage_path('app/public/migracao'), 0775, true);
        $arquivo = storage_path('app/public/migracao/mapa-empresas.json');
        file_put_contents($arquivo, json_encode($mapa, JSON_PRETTY_PRINT));
        $this->newLine();
        $this->info("Mapa id_antigo → empresa_id salvo em:");
        $this->line("  {$arquivo}");
        $this->line('  (o Passo 3 usa este arquivo para vincular as vagas)');
        $this->newLine();

        return 0;
    }

    /**
     * Um registro por CNPJ normalizado. Vence o visível; havendo empate,
     * o de id maior (mais recente). Devolve [selecionadas, descartadas].
     * `descartadas` mapeia id_descartado => id_vencedor.
     */
    private function deduplicar($todas): array
    {
        $porCnpj = [];
        $semCnpj = [];

        foreach ($todas as $c) {
            $digitos = preg_replace('/\D/', '', (string) $c->cnpj);
            if (strlen($digitos) !== 14) {
                $semCnpj[] = $c;                 // malformado ou vazio: não participa da mescla
                continue;
            }
            $porCnpj[$digitos][] = $c;
        }

        $selecionadas = collect($semCnpj);
        $descartadas  = collect();

        foreach ($porCnpj as $grupo) {
            usort($grupo, function ($a, $b) {
                $va = ((int) $a->deleted === 1 || (int) $a->active === 0) ? 0 : 1;
                $vb = ((int) $b->deleted === 1 || (int) $b->active === 0) ? 0 : 1;
                if ($va !== $vb) return $vb <=> $va;      // visível primeiro
                return $b->id <=> $a->id;                 // depois o mais recente
            });

            $vencedor = array_shift($grupo);
            $selecionadas->push($vencedor);

            foreach ($grupo as $perdedor) {
                $descartadas[$perdedor->id] = $vencedor->id;
            }
        }

        return [$selecionadas->sortBy('id')->values(), $descartadas];
    }

    private function mostrarSimulacao($selecionadas, $descartadas): void
    {
        $ocultas = $selecionadas->filter(fn($c) => (int) $c->deleted === 1 || (int) $c->active === 0)->count();
        $comLogo = $selecionadas->filter(fn($c) => !empty($c->logo_storage_location))->count();
        $semUser = $selecionadas->filter(fn($c) => empty($c->user_id))->count();
        $invalidos = $selecionadas->filter(function ($c) {
            $d = preg_replace('/\D/', '', (string) $c->cnpj);
            return !empty($c->cnpj) && strlen($d) !== 14;
        });

        $this->table(['Indicador', 'Total'], [
            ['a migrar',                   $selecionadas->count()],
            ['  visíveis',                 $selecionadas->count() - $ocultas],
            ['  ocultas (deleted_at)',     $ocultas],
            ['com logotipo no banco',      $comLogo],
            ['sem acesso (user criado)',   $semUser],
            ['descartadas na mescla',      $descartadas->count()],
        ]);

        if ($invalidos->isNotEmpty()) {
            $this->newLine();
            $this->warn('CNPJ inválido — entrarão vazios:');
            foreach ($invalidos as $c) {
                $this->line("  · {$c->id} — {$c->name} ({$c->cnpj})");
            }
        }

        if ($descartadas->isNotEmpty()) {
            $this->newLine();
            $this->line('Mescla por CNPJ (descartado → vencedor):');
            foreach ($descartadas as $de => $para) {
                $this->line("  · {$de} → {$para}");
            }
        }

        $this->newLine();
        $this->line('Nada foi gravado. Para executar:');
        $this->line('  <fg=yellow>php artisan ec:migrate-empresas --matriz=' . $this->option('matriz')
                  . ' --path="' . $this->option('path') . '"</>');
    }

    // ---------------------------------------------------------------
    // Normalizadores
    // ---------------------------------------------------------------

    /** Devolve o CNPJ com máscara padrão, ou null se não tiver 14 dígitos. */
    private function mapCnpj(?string $cnpj): ?string
    {
        $d = preg_replace('/\D/', '', (string) $cnpj);
        if (strlen($d) !== 14) return null;

        return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3)
             . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2);
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

    private function mapCep(?string $cep): ?string
    {
        if (empty($cep)) return null;
        $d = preg_replace('/\D/', '', $cep);
        return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : substr($d, 0, 9);
    }

    private function mapTelefone(?string $phone): ?string
    {
        if (empty($phone)) return null;
        $primeiro = trim(preg_split('/[;\/,]/', $phone)[0]);
        $d = preg_replace('/\D/', '', $primeiro);
        return substr($d ?: $primeiro, 0, 20);
    }
}
