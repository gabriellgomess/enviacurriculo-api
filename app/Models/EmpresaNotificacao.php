<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaNotificacao extends Model
{
    protected $table    = 'empresa_notificacoes';
    protected $fillable = ['empresa_id', 'titulo', 'corpo', 'lida'];
    protected $casts    = ['lida' => 'boolean'];

    /**
     * Cria uma notificação sem nunca quebrar o fluxo chamador.
     */
    public static function notificar(?int $empresaId, string $titulo, ?string $corpo = null): void
    {
        if (!$empresaId) {
            return;
        }

        try {
            static::create([
                'empresa_id' => $empresaId,
                'titulo'     => $titulo,
                'corpo'      => $corpo,
            ]);
        } catch (\Throwable) {
            // silencioso
        }
    }
}
