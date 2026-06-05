<?php

namespace Database\Seeders;

use App\Models\Candidato;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CandidatoUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'candidato@enviacurriculo.com.br'],
            [
                'name'     => 'Candidato Teste',
                'password' => Hash::make('Candidato@123'),
                'active'   => true,
            ]
        );

        UserRole::updateOrCreate(
            ['user_id' => $user->id, 'role' => 'candidato'],
        );

        $candidato = Candidato::updateOrCreate(
            ['user_id' => $user->id],
            [
                'cpf'           => '000.000.000-00',
                'nascimento'    => '1995-06-15',
                'cidade'        => 'São Paulo',
                'estado'        => 'SP',
                'cargo_desejado'=> 'Desenvolvedor',
                'active'        => true,
            ]
        );

        UserContext::updateOrCreate(
            ['user_id' => $user->id, 'role' => 'candidato'],
            ['context_id' => $candidato->id],
        );

        $this->command->info("Usuário candidato criado com sucesso!");
        $this->command->line("  E-mail: candidato@enviacurriculo.com.br");
        $this->command->line("  Senha:  Candidato@123");
    }
}
