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

        // Lê todos os candidatos do banco antigo
        $oldCandidates = DB::connection('mysql_antigo')
            ->table('ec_candidates')
            ->get();

        $this->info("Total de candidatos a migrar: " . $oldCandidates->count());

        $bar = $this->output->createProgressBar($oldCandidates->count());
        $bar->start();

        foreach ($oldCandidates as $oldCand) {
            DB::transaction(function () use ($oldCand, $oldStoragePath) {
                // 1. Obter usuário antigo associado
                $oldUser = DB::connection('mysql_antigo')
                    ->table('ec_users')
                    ->where('id', $oldCand->user_id)
                    ->first();

                if (!$oldUser) {
                    return; // Ignora se não houver usuário correspondente
                }

                // 2. Criar ou atualizar usuário no banco novo
                $user = User::updateOrCreate(
                    ['email' => $oldUser->email],
                    [
                        'name'     => $oldCand->name ?? $oldUser->name,
                        'phone'    => $oldCand->phone ?? $oldUser->phone,
                        'password' => $oldUser->password, // Preserva hash antigo
                        'active'   => $oldUser->status === 'active',
                    ]
                );

                // 3. Criar a Role de Candidato no novo sistema
                UserRole::firstOrCreate([
                    'user_id' => $user->id,
                    'role'    => 'candidato'
                ]);

                // 4. Buscar currículo/dados adicionais associados na tabela ec_curriculos
                $oldCurr = DB::connection('mysql_antigo')
                    ->table('ec_curriculos')
                    ->where('id_person', 'candidate_' . $oldCand->id)
                    ->first();

                // 5. Criar Candidato no novo sistema
                $candidato = Candidato::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'franquia_id'              => $oldCand->franchise_id, // Valide os IDs de Franquia previamente
                        'telefone'                 => $oldCand->phone ?? $oldUser->phone,
                        'nascimento'               => $oldCand->birth_date,
                        'cep'                      => $oldCand->cep ?? $oldCurr->cep ?? null,
                        'rua'                      => $oldCand->street ?? $oldCurr->street ?? null,
                        'numero'                   => $oldCand->number ?? $oldCurr->number ?? null,
                        'complemento'              => $oldCand->complement ?? $oldCurr->complement ?? null,
                        'bairro'                   => $oldCand->neighborhood ?? $oldCurr->neighborhood ?? null,
                        'cidade'                   => $oldCand->city ?? $oldCurr->city ?? null,
                        'estado'                   => $oldCand->state ?? $oldCurr->state ?? null,
                        'experiencia_profissional' => $oldCurr->professional_experience ?? null,
                        'educacao'                 => $oldCurr->education ?? null,
                        'habilidades'              => $oldCurr->skills ?? null,
                        'idiomas'                  => $oldCurr->languages ?? null,
                        'informacoes_adicionais'   => $oldCurr->additional_info ?? null,
                        'pretensao_salarial'       => $oldCand->desired_salary ?? null,
                        'latitude'                 => $oldCand->latitude ?? null,
                        'longitude'                => $oldCand->longitude ?? null,
                        'active'                   => $oldCand->active ?? true,
                    ]
                );

                // 6. Associar Contexto de Candidato no novo sistema
                UserContext::updateOrCreate(
                    ['user_id' => $user->id, 'role' => 'candidato'],
                    ['context_id' => $candidato->id]
                );

                // 7. Migrar o arquivo do currículo e registrá-lo em candidato_documentos
                if ($oldCurr && !empty($oldCurr->file_path)) {
                    $newPath = "candidatos/{$candidato->id}/" . basename($oldCurr->file_path);

                    // Se o caminho físico foi passado por parâmetro, copiar o arquivo real
                    if ($oldStoragePath) {
                        $fullOldPath = rtrim($oldStoragePath, '/') . "/Person/candidate_{$oldCand->id}/Curriculum/" . basename($oldCurr->file_path);
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
}
