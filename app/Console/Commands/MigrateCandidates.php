<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserContext;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;

/**
 * PASSO 4 do plano de migração.
 *
 * `ec_curriculos` → `candidatos` + `candidato_documentos`.
 * 12.442 currículos no dump → 12.250 cadastros e 11.443 documentos.
 *
 * Decisões aplicadas:
 *   17/I/N — franquia_id = NULL (banco global, visível a todas as franquias)
 *   18 — senha aleatória; acesso via "esqueci minha senha"
 *   19 — quem não tem usuário no sistema antigo entra sem login
 *   20 — currículos sem arquivo entram normalmente (999 casos)
 *   D  — um cadastro por e-mail, mantendo o mais recente
 *   L  — todos os arquivos do grupo viram documentos; o mais recente fica ativo
 *   H  — o campo `consultant` do sistema antigo não é migrado
 *
 * Gera storage/app/migracao-mapa-candidatos.json com id_curriculo_antigo →
 * candidato_id, incluindo os descartados (que apontam para o mantido). Os
 * Passos 5, 6 e 7 dependem desse mapa (decisão K).
 */
class MigrateCandidates extends Command
{
    use \App\Console\Commands\Concerns\PreservaDatas;

    protected $signature = 'ec:migrate-candidates
                            {--path= : Caminho da pasta storage/app do sistema antigo}
                            {--dry-run : Apenas simula, sem gravar nada}
                            {--limite= : Processa apenas os N primeiros grupos (para teste)}';

    protected $description = 'Migra os candidatos e currículos do sistema antigo';

    public function handle(): int
    {
        $path   = $this->option('path');
        $dry    = (bool) $this->option('dry-run');
        $limite = (int) $this->option('limite');

        $this->newLine();
        $this->info('PASSO 4 — Candidatos');
        $this->line('  modo:      ' . ($dry ? 'SIMULAÇÃO' : 'EXECUÇÃO'));
        $this->line('  currículos: ' . ($path ?: 'não informado — arquivos não serão copiados'));
        $this->newLine();

        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error('Sem conexão com o banco antigo: ' . $e->getMessage());
            return 1;
        }

        $this->line('  lendo ec_curriculos...');
        $todos = DB::connection('mysql_antigo')
            ->table('ec_curriculos')
            ->orderBy('id')
            ->get();

        $this->line("  currículos no banco antigo: {$todos->count()}");

        // ---------- Agrupamento por e-mail (decisões D e L) ----------
        $grupos = $this->agrupar($todos);
        if ($limite > 0) {
            $grupos = array_slice($grupos, 0, $limite, true);
            $this->warn("  limitado aos primeiros {$limite} grupos");
        }

        $totalDocs = $todos->filter(fn($c) => !empty($c->file_path))->count();
        $noEscopo  = array_sum(array_map('count', $grupos));
        $descartados = $noEscopo - count($grupos);

        $this->line('  cadastros após deduplicar: ' . count($grupos));
        $this->line("  currículos que viram apenas documento: {$descartados}");
        $this->newLine();

        if ($dry) {
            $this->mostrarSimulacao($todos, $grupos, $totalDocs);
            return 0;
        }

        // ---------- Execução ----------
        $bar = $this->output->createProgressBar(count($grupos));
        $bar->start();

        $mapa      = [];
        $criados   = 0;
        $comLogin  = 0;
        $documentos = 0;
        $arquivos  = 0;
        $semPerfil = [];
        $erros     = [];

        foreach ($grupos as $email => $registros) {
            try {
                $resultado = DB::transaction(function () use ($email, $registros, $path) {
                    $principal = $registros[0];   // mais recente

                    // Decisão 18: senha sempre aleatória
                    $user = User::where('email', $email)->first();
                    $novoLogin = false;
                    if (!$user) {
                        $user = User::create([
                            'name'     => $principal->person_name ?: 'Candidato sem nome',
                            'email'    => $email,
                            'phone'    => $this->mapTelefone($principal->person_phone),
                            'password' => Hash::make(Str::random(24)),
                            'active'   => true,
                        ]);
                        $novoLogin = true;
                    }

                    // Não contaminar conta admin com perfil de candidato.
                    // O cadastro entra no acervo (a franquia enxerga), mas a
                    // conta não ganha o papel nem o contexto de candidato.
                    $ehAdmin = UserRole::where('user_id', $user->id)->where('role', 'admin')->exists();

                    if (!$ehAdmin) {
                        UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'candidato']);
                    }

                    $candidato = Candidato::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'franquia_id'              => null,   // decisões 17, I e N
                            'telefone'                 => $this->mapTelefone($principal->person_phone),
                            'cep'                      => $this->mapCep($principal->cep),
                            'rua'                      => $this->limitar($principal->street, 255),
                            'numero'                   => $this->limitar($principal->number, 20),
                            'complemento'              => $this->limitar($principal->complement, 100),
                            'bairro'                   => $this->limitar($principal->neighborhood, 100),
                            'cidade'                   => $this->limitar($principal->city, 255),
                            'estado'                   => $this->mapEstado($principal->state),
                            'experiencia_profissional' => $principal->professional_experience,
                            'educacao'                 => $principal->education,
                            'habilidades'              => $principal->skills,
                            'idiomas'                  => $principal->languages,
                            'informacoes_adicionais'   => $principal->additional_info,
                            'latitude'                 => $principal->latitude,
                            'longitude'                => $principal->longitude,
                            'active'                   => true,
                        ]
                    );

                    // Data original do cadastro no sistema antigo
                    $criadoEm = $principal->created_at ?: $principal->created_date;
                    $this->preservarDatas('candidatos', $candidato->id, $criadoEm, $principal->updated_at);
                    if ($novoLogin) {
                        $this->preservarDatas('users', $user->id, $criadoEm, $principal->updated_at);
                    }

                    // Sem contexto o candidato não loga
                    if (!$ehAdmin) {
                        UserContext::updateOrCreate(
                            ['user_id' => $user->id, 'role' => 'candidato'],
                            ['context_id' => $candidato->id]
                        );
                    }

                    // Decisão L: todos os arquivos do grupo viram documento
                    $docs = 0;
                    $copiados = 0;
                    foreach ($registros as $i => $reg) {
                        if (empty($reg->file_path)) continue;

                        $nome = $reg->file_name ?: basename($reg->file_path);
                        $destino = "candidatos/{$candidato->id}/{$reg->id}-" . basename($reg->file_path);

                        if ($path) {
                            $origem = rtrim($path, '/') . '/' . ltrim($reg->file_path, '/');
                            if (is_file($origem)) {
                                Storage::disk('public')->put($destino, file_get_contents($origem));
                                $copiados++;
                            }
                        }

                        $doc = CandidatoDocumento::updateOrCreate(
                            ['candidato_id' => $candidato->id, 'arquivo_path' => $destino],
                            [
                                'tipo'         => 'curriculo',
                                'arquivo_nome' => $nome,
                                'tamanho_kb'   => (int) ceil(((int) $reg->file_size) / 1024),
                                'ativo'        => $i === 0,   // só o mais recente fica ativo
                            ]
                        );

                        $this->preservarDatas(
                            'candidato_documentos',
                            $doc->id,
                            $reg->created_at ?: $reg->created_date,
                            $reg->updated_at
                        );
                        $docs++;
                    }

                    return compact('candidato', 'novoLogin', 'docs', 'copiados', 'registros', 'ehAdmin');
                });

                foreach ($registros as $reg) {
                    $mapa[$reg->id] = $resultado['candidato']->id;
                }

                $criados++;
                if ($resultado['novoLogin']) $comLogin++;
                if ($resultado['ehAdmin'])   $semPerfil[] = $email;
                $documentos += $resultado['docs'];
                $arquivos   += $resultado['copiados'];
            } catch (\Throwable $e) {
                $erros[] = "{$email}: " . Str::limit($e->getMessage(), 120);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Concluído: {$criados} candidato(s).");
        $this->table(['Indicador', 'Total'], [
            ['cadastros criados',      $criados],
            ['usuários criados',       $comLogin],
            ['documentos registrados', $documentos],
            ['arquivos copiados',      $path ? $arquivos : 'sem --path'],
            ['erros',                  count($erros)],
        ]);

        if ($semPerfil) {
            $this->newLine();
            $this->warn('Cadastros criados SEM perfil de candidato (e-mail é de conta admin):');
            foreach ($semPerfil as $e) $this->line("  · {$e}");
            $this->line('  (o currículo entra no acervo, mas a conta admin não vira candidato)');
        }

        if ($erros) {
            $this->newLine();
            $this->error('Falhas:');
            foreach (array_slice($erros, 0, 20) as $e) $this->line("  · {$e}");
            if (count($erros) > 20) $this->line('  ... e mais ' . (count($erros) - 20));
        }

        $arquivo = storage_path('app/migracao-mapa-candidatos.json');
        file_put_contents($arquivo, json_encode($mapa, JSON_PRETTY_PRINT));
        $this->newLine();
        $this->info('Mapa id_curriculo_antigo → candidato_id salvo em:');
        $this->line("  {$arquivo}");
        $this->line('  (' . count($mapa) . ' entradas — os Passos 5, 6 e 7 dependem dele)');
        $this->newLine();

        return 0;
    }

    /**
     * Agrupa por e-mail, mais recente primeiro (decisão D).
     * Devolve [email => [registros...]].
     */
    private function agrupar($todos): array
    {
        $grupos = [];

        foreach ($todos as $c) {
            $email = strtolower(trim((string) $c->person_email));
            if ($email === '') {
                // Sem e-mail não há como identificar a pessoa: cadastro isolado
                $email = 'cv_' . $c->id . '@banco.local';
            }
            $grupos[$email][] = $c;
        }

        foreach ($grupos as $email => $registros) {
            usort($registros, function ($a, $b) {
                $da = $a->created_at ?: ($a->created_date ?: '');
                $db = $b->created_at ?: ($b->created_date ?: '');
                if ($da !== $db) return strcmp($db, $da);   // mais recente primeiro
                return $b->id <=> $a->id;
            });
            $grupos[$email] = $registros;
        }

        return $grupos;
    }

    private function mostrarSimulacao($todos, array $grupos, int $totalDocs): void
    {
        $duplicados = array_filter($grupos, fn($g) => count($g) > 1);
        $semArquivo = $todos->filter(fn($c) => empty($c->file_path))->count();

        $porPasta = [];
        foreach ($todos as $c) {
            if (empty($c->file_path)) continue;
            $raiz = explode('/', $c->file_path)[0];
            $porPasta[$raiz] = ($porPasta[$raiz] ?? 0) + 1;
        }

        $this->table(['Indicador', 'Total'], [
            ['currículos no dump',         $todos->count()],
            ['cadastros a criar',          count($grupos)],
            ['e-mails com mais de um CV',  count($duplicados)],
            ['documentos a registrar',     $totalDocs],
            ['currículos sem arquivo',     $semArquivo],
        ]);

        $this->newLine();
        $this->line('Arquivos por pasta de origem:');
        foreach ($porPasta as $pasta => $qtd) {
            $this->line("  · {$pasta}/: {$qtd}");
        }

        $this->newLine();
        $this->line('Nada foi gravado. Para executar:');
        $this->line('  <fg=yellow>php artisan ec:migrate-candidates</>');
    }

    // ---------------------------------------------------------------

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

    /**
     * O sistema antigo não limitava os campos de endereço. Quatro currículos
     * trazem texto de carta de apresentação no campo de complemento, que aqui
     * aceita 100 caracteres.
     */
    private function limitar(?string $v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
