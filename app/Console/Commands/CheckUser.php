<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CheckUser extends Command
{
    protected $signature = 'user:check {email} {password}';
    protected $description = 'Verifica credenciais de um usuário';

    public function handle()
    {
        $email    = $this->argument('email');
        $password = $this->argument('password');

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("Usuário não encontrado: {$email}");
            return 1;
        }

        $this->info("ID: {$user->id}");
        $this->info("Nome: {$user->name}");
        $this->info("Active: " . ($user->active ? 'sim' : 'não'));
        $this->info("Roles: " . $user->roles->pluck('role')->implode(', '));
        $this->info("Senha bate: " . (Hash::check($password, $user->password) ? 'SIM' : 'NÃO'));

        return 0;
    }
}
