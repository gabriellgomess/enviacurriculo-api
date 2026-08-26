<?php

namespace Database\Seeders;

use App\Models\Candidato;
use App\Models\Cargo;
use App\Models\EmpresaCurriculo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra em `cargos` qualquer valor de `cargos_interesse` já usado em
 * candidatos/currículos (texto livre salvo antes da lista gerenciada existir)
 * mas que ainda não consta na tabela.
 *
 * Sem isso, esses valores ficam "selecionados" no candidato mas não aparecem
 * como opção ao reabrir o select — o MultiSelect só lista o que está em
 * `cargos`, e a comparação é por string exata, então o nome é inserido tal
 * como está salvo (sem normalizar maiúsculas/minúsculas).
 */
class SyncCargosExistentesSeeder extends Seeder
{
    public function run(): void
    {
        $usados = collect()
            ->merge(Candidato::whereNotNull('cargos_interesse')->pluck('cargos_interesse'))
            ->merge(EmpresaCurriculo::whereNotNull('cargos_interesse')->pluck('cargos_interesse'))
            ->flatten()
            ->map(fn ($nome) => trim((string) $nome))
            ->filter()
            ->unique()
            ->values();

        $existentes = Cargo::pluck('nome')->all();
        $faltando   = $usados->reject(fn ($nome) => in_array($nome, $existentes, true));

        if ($faltando->isEmpty()) {
            $this->command?->info('Nenhum cargo em uso fora da lista cadastrada.');
            return;
        }

        $now = now();
        DB::table('cargos')->insert(
            $faltando->map(fn ($nome) => [
                'nome'       => $nome,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        $this->command?->info("{$faltando->count()} cargo(s) inserido(s): " . $faltando->implode(', '));
    }
}
