<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Preserva as datas de criação e atualização do sistema antigo.
 *
 * Os models não têm `created_at`/`updated_at` no $fillable, então passá-los
 * pelo updateOrCreate não funciona — o Laravel ignora e grava a data de hoje.
 * A gravação precisa ser direta na tabela.
 */
trait PreservaDatas
{
    /**
     * @param string      $tabela   Tabela do sistema novo
     * @param int         $id       ID do registro recém-gravado
     * @param string|null $criado   Data de criação no sistema antigo
     * @param string|null $alterado Data de atualização no sistema antigo
     */
    protected function preservarDatas(string $tabela, int $id, ?string $criado, ?string $alterado = null): void
    {
        $criado = $this->normalizarData($criado);
        if ($criado === null) return;

        DB::table($tabela)->where('id', $id)->update([
            'created_at' => $criado,
            'updated_at' => $this->normalizarData($alterado) ?? $criado,
        ]);
    }

    /** Descarta datas vazias ou zeradas do MySQL antigo. */
    private function normalizarData(?string $data): ?string
    {
        if (empty($data)) return null;
        if (str_starts_with($data, '0000-00-00')) return null;

        return $data;
    }
}
