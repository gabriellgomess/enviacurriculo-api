<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password {email} {password}';
    protected $description = 'Redefine a senha de um usuário pelo e-mail';

    public function handle()
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (!$user) {
            $this->error('Usuário não encontrado.');
            return 1;
        }

        $user->password = $this->argument('password');
        $user->save();

        $this->info("Senha de {$user->email} atualizada com sucesso.");
        return 0;
    }
}
