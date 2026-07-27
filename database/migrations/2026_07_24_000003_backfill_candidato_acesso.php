<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige candidatos inseridos pelo banco de currículos que ficaram sem
 * papel (user_roles) e sem contexto (user_contexts) de "candidato", o que
 * impedia o acesso ao painel mesmo após redefinir a senha. Também ativa o
 * usuário quando o candidato está ativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $candidatos = DB::table('candidatos')
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'active']);

        foreach ($candidatos as $c) {
            $temRole = DB::table('user_roles')
                ->where('user_id', $c->user_id)
                ->where('role', 'candidato')
                ->exists();

            if (!$temRole) {
                DB::table('user_roles')->insert([
                    'user_id'    => $c->user_id,
                    'role'       => 'candidato',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $temContexto = DB::table('user_contexts')
                ->where('user_id', $c->user_id)
                ->where('role', 'candidato')
                ->where('context_id', $c->id)
                ->exists();

            if (!$temContexto) {
                DB::table('user_contexts')->insert([
                    'user_id'    => $c->user_id,
                    'role'       => 'candidato',
                    'context_id' => $c->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Candidato ativo → usuário deve poder logar
            if ($c->active) {
                DB::table('users')
                    ->where('id', $c->user_id)
                    ->where('active', false)
                    ->update(['active' => true, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Correção de dados — sem rollback automático.
    }
};
