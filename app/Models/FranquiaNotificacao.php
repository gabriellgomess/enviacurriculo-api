<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaNotificacao extends Model
{
    protected $table    = 'franquia_notificacoes';
    protected $fillable = ['franquia_id', 'titulo', 'corpo', 'lida'];
    protected $casts    = ['lida' => 'boolean'];

    /**
     * Cria uma notificação sem nunca quebrar o fluxo chamador.
     */
    public static function notificar(?int $franquiaId, string $titulo, ?string $corpo = null): void
    {
        if (!$franquiaId) {
            return;
        }

        try {
            static::create([
                'franquia_id' => $franquiaId,
                'titulo'      => $titulo,
                'corpo'       => $corpo,
            ]);
        } catch (\Throwable) {
            // silencioso
        }
    }
}
