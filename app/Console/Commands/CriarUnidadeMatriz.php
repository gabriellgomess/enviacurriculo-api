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
 * PASSO 0 do plano de migração.
 *
 * Cria a franquia "Unidade Matriz", que assume:
 *   - as 226 empresas (decisão 7)
 *   - 5.369 pareceres e 9.965 vínculos de consultores que não viram franquia
 *     (decisões 3 e O)
 *
 * Todos os comandos seguintes recebem o ID gerado aqui via --matriz.
 * Idempotente: rodar de novo não duplica nada.
 */
class CriarUnidadeMatriz extends Command
{
    protected $signature = 'ec:criar-unidade-matriz
                            {--nome=Unidade Matriz : Nome da franquia}
                            {--email= : E-mail de acesso (padrão: matriz@enviacurriculo.com.br)}';

    protected $description = 'Cria a franquia Unidade Matriz, responsável pelo acervo migrado';

    public function handle(): int
    {
        $nome  = $this->option('nome');
        $email = $this->option('email') ?: 'matriz@enviacurriculo.com.br';

        $this->newLine();
        $this->info('PASSO 0 — Unidade Matriz');
        $this->line("  nome:  {$nome}");
        $this->line("  email: {$email}");
        $this->newLine();

        // Já existe?
        $existente = Franquia::where('nome', $nome)->first();
        if ($existente) {
            $this->warn("Já existe uma franquia '{$nome}' com ID {$existente->id}.");
            $this->line('Nada foi criado.');
            $this->newLine();
            $this->info("Use nos próximos comandos:  --matriz={$existente->id}");
            return 0;
        }

        $senha = Str::random(24);

        $resultado = DB::transaction(function () use ($nome, $email, $senha) {
            $franquia = Franquia::create([
                'nome'        => $nome,
                'responsavel' => $nome,
                'tipo'        => 'premium',
                'email'       => $email,
                'active'      => true,
                'descricao'   => 'Franquia responsável pelo acervo migrado do sistema antigo. '
                               . 'Assume as empresas e o histórico de consultores que não '
                               . 'foram convertidos em franquia.',
            ]);

            // Mesmo padrão do FranquiaController
            $franquia->update([
                'codigo' => 'FR-' . str_pad($franquia->id, 4, '0', STR_PAD_LEFT),
            ]);

            $user = User::create([
                'name'     => $nome,
                'email'    => $email,
                'password' => Hash::make($senha),
                'active'   => true,
            ]);

            UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'franquia']);

            UserContext::updateOrCreate(
                ['user_id' => $user->id, 'role' => 'franquia'],
                ['context_id' => $franquia->id]
            );

            return ['franquia' => $franquia, 'user' => $user];
        });

        $franquia = $resultado['franquia'];

        $this->newLine();
        $this->info('Criado com sucesso.');
        $this->table(['Campo', 'Valor'], [
            ['franquia_id', $franquia->id],
            ['codigo',      $franquia->codigo],
            ['user_id',     $resultado['user']->id],
            ['email',       $email],
        ]);

        $this->newLine();
        $this->warn('A senha foi gerada aleatoriamente e NÃO é exibida.');
        $this->warn('Para acessar, use "esqueci minha senha" com o e-mail acima.');

        $this->newLine();
        $this->info('Guarde este ID — todos os próximos comandos precisam dele:');
        $this->line("  <fg=yellow>--matriz={$franquia->id}</>");
        $this->newLine();

        return 0;
    }
}
