<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@enviacurriculo.com.br'],
            [
                'name'     => 'Administrador',
                'password' => Hash::make('Admin@123456'),
                'active'   => true,
            ]
        );

        UserRole::updateOrCreate(
            ['user_id' => $user->id, 'role' => 'admin'],
        );

        $this->command->info("Usuário admin criado com sucesso!");
        $this->command->line("  E-mail: admin@enviacurriculo.com.br");
        $this->command->line("  Senha:  Admin@123456");
        $this->command->warn("  Altere a senha após o primeiro acesso.");
    }
}
