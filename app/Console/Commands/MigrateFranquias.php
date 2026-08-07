<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Franquia;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserContext;

/**
 * PASSO 1 do plano de migração.
 *
 * Converte em franquias os 12 consultores indicados nominalmente pelo cliente.
 *
 * IMPORTANTE — a seleção é por LISTA FIXA, não por `active` (decisão O).
 * O cliente inativou todos os consultores no sistema antigo para bloquear acesso
 * durante a migração, então `ec_consultants.active` não indica vínculo. Usar o
 * campo criaria 1 franquia em vez de 12.
 *
 * Decisões aplicadas:
 *   1 — nome da pessoa vira o nome da franquia (ignora company_name)
 *   2 — código gerado no padrão novo (FR-0001)
 *   4 — rg, specialty, commission_rate e dismissal_reason não são migrados
 *   5 — documentos dos consultores não são migrados
 *  18 — senha aleatória; acesso via "esqueci minha senha"
 */
class MigrateFranquias extends Command
{
    protected $signature = 'ec:migrate-franquias
                            {--dry-run : Apenas simula, sem gravar nada}';

    protected $description = 'Migra os 12 consultores da lista do cliente para franquias';

    /**
     * ec_consultants.id dos consultores que viram franquia (decisão O).
     * Lista fechada com o cliente em 07/08/2026.
     */
    private const CONSULTORES = [10, 13, 15, 16, 32, 54, 112, 125, 146, 147, 171, 186];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->newLine();
        $this->info('PASSO 1 — Franquias');
        $this->line($dry ? '  modo: SIMULAÇÃO (nada será gravado)' : '  modo: EXECUÇÃO');
        $this->newLine();

        // Conexão com o banco antigo
        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error("Não foi possível conectar ao banco antigo (conexão 'mysql_antigo').");
            $this->error('Verifique as variáveis DB_OLD_* no ambiente.');
            $this->error('Erro: ' . $e->getMessage());
            return 1;
        }

        $consultores = DB::connection('mysql_antigo')
            ->table('ec_consultants')
            ->whereIn('id', self::CONSULTORES)
            ->orderBy('id')
            ->get();

        $esperado = count(self::CONSULTORES);
        $this->line("  esperados: {$esperado}   encontrados: {$consultores->count()}");

        if ($consultores->count() !== $esperado) {
            $achados = $consultores->pluck('id')->all();
            $faltando = array_diff(self::CONSULTORES, $achados);
            $this->error('IDs não encontrados no banco antigo: ' . implode(', ', $faltando));
            return 1;
        }

        $this->newLine();

        $criadas = 0;
        $atualizadas = 0;
        $linhas = [];

        foreach ($consultores as $old) {
            if ($dry) {
                $linhas[] = [
                    $old->id,
                    Str::limit($old->name, 30),
                    $old->email,
                    ($old->city ?: '—') . '/' . ($old->state ?: '—'),
                    Franquia::where('email', $old->email)->exists() ? 'já existe' : 'criar',
                ];
                continue;
            }

            $resultado = DB::transaction(function () use ($old) {
                $jaExistia = Franquia::where('email', $old->email)->exists();

                // Decisão 1: sempre o nome da pessoa
                $franquia = Franquia::updateOrCreate(
                    ['email' => $old->email],
                    [
                        'nome'                 => $old->name,
                        'responsavel'          => $old->name,
                        'tipo'                 => 'start',
                        'cpf'                  => $this->mapDocumento($old->cpf, 14),
                        'cnpj'                 => $this->mapDocumento($old->cnpj, 18),
                        'data_nascimento'      => $old->birth_date,
                        'telefone'             => $this->mapTelefone($old->phone),
                        'data_inicio_parceria' => $old->admission_date,
                        // Endereço pessoal
                        'cep'                  => $this->mapCep($old->cep),
                        'logradouro'           => $old->street,
                        'numero'               => $old->number,
                        'complemento'          => $old->complement,
                        'bairro'               => $old->neighborhood,
                        'cidade'               => $old->city,
                        'estado'               => $this->mapEstado($old->state),
                        // Dados bancários
                        'nome_banco'           => $old->bank_name,
                        'codigo_banco'         => $old->bank_code,
                        'agencia'              => $old->agency,
                        'numero_conta'         => $old->account_number,
                        'tipo_conta'           => in_array($old->account_type, ['corrente', 'poupanca'], true)
                                                    ? $old->account_type : null,
                        'chave_pix'            => $old->pix_key,
                        'active'               => true,
                    ]
                );

                // Decisão 2: código no padrão novo
                if (empty($franquia->codigo)) {
                    $franquia->update([
                        'codigo' => 'FR-' . str_pad($franquia->id, 4, '0', STR_PAD_LEFT),
                    ]);
                }

                // Decisão 18: senha aleatória, acesso por "esqueci minha senha"
                $user = User::where('email', $old->email)->first();
                if (!$user) {
                    $user = User::create([
                        'name'     => $old->name,
                        'email'    => $old->email,
                        'phone'    => $this->mapTelefone($old->phone),
                        'password' => Hash::make(Str::random(24)),
                        'active'   => true,
                    ]);
                }

                UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'franquia']);

                UserContext::updateOrCreate(
                    ['user_id' => $user->id, 'role' => 'franquia'],
                    ['context_id' => $franquia->id]
                );

                return ['franquia' => $franquia, 'novo' => !$jaExistia];
            });

            $f = $resultado['franquia'];
            $resultado['novo'] ? $criadas++ : $atualizadas++;

            $linhas[] = [
                $old->id,
                Str::limit($old->name, 30),
                $f->codigo,
                $f->id,
                $resultado['novo'] ? 'criada' : 'atualizada',
            ];
        }

        $this->table(
            $dry
                ? ['id antigo', 'Nome', 'E-mail', 'Cidade/UF', 'Ação']
                : ['id antigo', 'Nome', 'Código', 'franquia_id', 'Status'],
            $linhas
        );

        if ($dry) {
            $this->newLine();
            $this->line('Nada foi gravado. Para executar:');
            $this->line('  <fg=yellow>php artisan ec:migrate-franquias</>');
            return 0;
        }

        $this->newLine();
        $this->info("Concluído: {$criadas} criada(s), {$atualizadas} atualizada(s).");

        // Alerta sobre cadastros incompletos
        $semEndereco = Franquia::whereIn('email', $consultores->pluck('email'))
            ->where(function ($q) {
                $q->whereNull('cidade')->orWhereNull('estado');
            })->get();

        if ($semEndereco->isNotEmpty()) {
            $this->newLine();
            $this->warn('Franquias sem cidade ou estado (não aparecem no mapa):');
            foreach ($semEndereco as $f) {
                $this->line("  · {$f->codigo} — {$f->nome}");
            }
        }

        return 0;
    }

    // ---------------------------------------------------------------
    // Normalizadores (mesma lógica de MigrateCandidates)
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
        $limpo = preg_replace('/\D/', '', $cep);
        return strlen($limpo) === 8
            ? substr($limpo, 0, 5) . '-' . substr($limpo, 5)
            : substr($limpo, 0, 9);
    }

    private function mapTelefone(?string $phone): ?string
    {
        if (empty($phone)) return null;
        $primeiro = trim(preg_split('/[;\/,]/', $phone)[0]);
        $limpo = preg_replace('/\D/', '', $primeiro);
        return substr($limpo ?: $primeiro, 0, 20);
    }

    /**
     * CPF e CNPJ têm limite de tamanho no schema novo (14 e 18).
     * O sistema antigo grava com e sem máscara.
     */
    private function mapDocumento(?string $doc, int $max): ?string
    {
        if (empty($doc)) return null;
        $doc = trim($doc);
        return mb_strlen($doc) > $max ? preg_replace('/\D/', '', $doc) : $doc;
    }
}
