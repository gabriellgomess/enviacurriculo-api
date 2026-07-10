<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserContext;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;

class MigrateCandidates extends Command
{
    protected $signature = 'ec:migrate-candidates {--path= : O caminho absoluto para a pasta storage/app do sistema antigo}';
    protected $description = 'Migra os candidatos, currículos e arquivos do sistema antigo para o novo banco de dados';

    public function handle()
    {
        $oldStoragePath = $this->option('path');
        $this->info("Iniciando migração de candidatos...");

        // Testa conexão com o banco antigo
        try {
            DB::connection('mysql_antigo')->getPdo();
        } catch (\Exception $e) {
            $this->error("Não foi possível conectar ao banco de dados antigo. Verifique as configurações 'mysql_antigo' em config/database.php.");
            $this->error("Erro: " . $e->getMessage());
            return 1;
        }

        // Lê todos os currículos do banco antigo
        $oldCurriculos = DB::connection('mysql_antigo')
            ->table('ec_curriculos')
            ->get();

        $this->info("Total de currículos a migrar: " . $oldCurriculos->count());

        $bar = $this->output->createProgressBar($oldCurriculos->count());
        $bar->start();

        foreach ($oldCurriculos as $oldCurr) {
            DB::transaction(function () use ($oldCurr, $oldStoragePath) {
                // 1. Obter dados do usuário e buscar correspondência no ec_users/ec_candidates
                $email = $oldCurr->person_email;
                if (empty($email)) {
                    $email = 'cv_' . $oldCurr->id . '@banco.local';
                }

                $oldUser = DB::connection('mysql_antigo')
                    ->table('ec_users')
                    ->where('email', $email)
                    ->first();

                // Busca franquia_id e active do ec_candidates se houver
                $franchiseId = null;
                $active = true;
                $birthDate = null;
                $desiredSalary = null;
                $oldCand = null;

                if (!empty($oldCurr->id_person) && str_starts_with($oldCurr->id_person, 'candidate_')) {
                    $candId = (int) str_replace('candidate_', '', $oldCurr->id_person);
                    $oldCand = DB::connection('mysql_antigo')
                        ->table('ec_candidates')
                        ->where('id', $candId)
                        ->first();
                }

                if (!$oldCand && !empty($oldCurr->person_email)) {
                    $oldCand = DB::connection('mysql_antigo')
                        ->table('ec_candidates')
                        ->where('email', $oldCurr->person_email)
                        ->first();
                }

                if ($oldCand) {
                    $franchiseId = $oldCand->franchise_id;
                    $active = (bool) $oldCand->active;
                    $birthDate = $oldCand->birth_date;
                    $desiredSalary = $oldCand->desired_salary;
                }

                // 2. Criar ou atualizar usuário no banco novo
                $user = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'name'     => $oldCurr->person_name ?? ($oldUser->name ?? ($oldCand->name ?? 'Candidato Sem Nome')),
                        'phone'    => $this->mapTelefone($oldCurr->person_phone ?? ($oldCand->phone ?? ($oldUser->phone ?? null))),
                        'password' => $oldUser->password ?? bcrypt(\Illuminate\Support\Str::random(16)), // Preserva hash antigo ou gera nova senha aleatória
                        'active'   => $oldUser ? ($oldUser->status === 'active') : $active,
                    ]
                );

                // 3. Criar a Role de Candidato no novo sistema
                UserRole::firstOrCreate([
                    'user_id' => $user->id,
                    'role'    => 'candidato'
                ]);

                // 4. Criar Candidato no novo sistema
                $candidato = Candidato::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'franquia_id'              => $franchiseId,
                        'telefone'                 => $this->mapTelefone($oldCurr->person_phone ?? ($oldCand->phone ?? ($oldUser->phone ?? null))),
                        'nascimento'               => $birthDate,
                        'cep'                      => $this->mapCep($oldCurr->cep ?? ($oldCand->cep ?? null)),
                        'rua'                      => $oldCurr->street ?? ($oldCand->street ?? null),
                        'numero'                   => $oldCurr->number ?? ($oldCand->number ?? null),
                        'complemento'              => $oldCurr->complement ?? ($oldCand->complement ?? null),
                        'bairro'                   => $oldCurr->neighborhood ?? ($oldCand->neighborhood ?? null),
                        'cidade'                   => $oldCurr->city ?? ($oldCand->city ?? null),
                        'estado'                   => $this->mapEstado($oldCurr->state ?? ($oldCand->state ?? null)),
                        'experiencia_profissional' => $oldCurr->professional_experience ?? null,
                        'educacao'                 => $oldCurr->education ?? null,
                        'habilidades'              => $oldCurr->skills ?? null,
                        'idiomas'                  => $oldCurr->languages ?? null,
                        'informacoes_adicionais'   => $oldCurr->additional_info ?? null,
                        'pretensao_salarial'       => $desiredSalary,
                        'latitude'                 => $oldCand->latitude ?? $oldCurr->latitude ?? null,
                        'longitude'                => $oldCand->longitude ?? $oldCurr->longitude ?? null,
                        'active'                   => $active,
                    ]
                );

                // 5. Associar Contexto de Candidato no novo sistema
                UserContext::updateOrCreate(
                    ['user_id' => $user->id, 'role' => 'candidato'],
                    ['context_id' => $candidato->id]
                );

                // 6. Migrar o arquivo do currículo e registrá-lo em candidato_documentos
                if (!empty($oldCurr->file_path)) {
                    $newPath = "candidatos/{$candidato->id}/" . basename($oldCurr->file_path);

                    // Se o caminho físico foi passado por parâmetro, copiar o arquivo real
                    if ($oldStoragePath) {
                        $fullOldPath = rtrim($oldStoragePath, '/') . '/' . ltrim($oldCurr->file_path, '/');
                        if (file_exists($fullOldPath)) {
                            Storage::disk('public')->put($newPath, file_get_contents($fullOldPath));
                        }
                    }

                    CandidatoDocumento::updateOrCreate(
                        ['candidato_id' => $candidato->id, 'arquivo_nome' => $oldCurr->file_name],
                        [
                            'tipo'         => 'curriculo',
                            'arquivo_path' => $newPath,
                            'tamanho_kb'   => (int) ceil(($oldCurr->file_size ?? 0) / 1024),
                            'ativo'        => true,
                        ]
                    );
                }
            });

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Migração concluída com sucesso!");
        return 0;
    }

    private function mapEstado(?string $state): ?string
    {
        if (empty($state)) return null;
        $state = trim(mb_strtoupper($state, 'UTF-8'));
        
        if (strlen($state) === 2) {
            return $state;
        }
        
        $mapping = [
            'ACRE' => 'AC',
            'ALAGOAS' => 'AL',
            'AMAPA' => 'AP',
            'AMAZONAS' => 'AM',
            'BAHIA' => 'BA',
            'CEARA' => 'CE',
            'DISTRITO FEDERAL' => 'DF',
            'ESPIRITO SANTO' => 'ES',
            'GOIAS' => 'GO',
            'MARANHAO' => 'MA',
            'MATO GROSSO' => 'MT',
            'MATO GROSSO DO SUL' => 'MS',
            'MINAS GERAIS' => 'MG',
            'PARA' => 'PA',
            'PARAIBA' => 'PB',
            'PARANA' => 'PR',
            'PERNAMBUCO' => 'PE',
            'PIAUI' => 'PI',
            'RIO DE JANEIRO' => 'RJ',
            'RIO GRANDE DO NORTE' => 'RN',
            'RIO GRANDE DO SUL' => 'RS',
            'RONDONIA' => 'RO',
            'RORAIMA' => 'RR',
            'SANTA CATARINA' => 'SC',
            'SAO PAULO' => 'SP',
            'SERGIPE' => 'SE',
            'TOCANTINS' => 'TO'
        ];
        
        // Remove accents
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $state);
        $normalized = preg_replace('/[^A-Z\s]/', '', mb_strtoupper($normalized));
        $normalized = trim($normalized);
        
        if (isset($mapping[$normalized])) {
            return $mapping[$normalized];
        }
        
        return substr($state, 0, 2);
    }

    private function mapCep(?string $cep): ?string
    {
        if (empty($cep)) return null;
        $clean = preg_replace('/\D/', '', $cep);
        if (strlen($clean) === 8) {
            return substr($clean, 0, 5) . '-' . substr($clean, 5);
        }
        return substr($clean, 0, 9);
    }

    private function mapTelefone(?string $phone): ?string
    {
        if (empty($phone)) return null;
        
        $parts = preg_split('/[;\/,]/', $phone);
        $first = trim($parts[0]);
        $clean = preg_replace('/\D/', '', $first);
        
        if (strlen($clean) > 20) {
            return substr($clean, 0, 20);
        }
        
        return $clean ?: substr($first, 0, 20);
    }
}
