<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Uma mudança de status de um vínculo. Gravado pelo próprio model Envio
 * (eventos created/updated), então vale para qualquer painel que altere o
 * status — franquia, empresa ou admin — sem depender de cada tela.
 */
class EnvioStatusHistorico extends Model
{
    protected $table = 'envio_status_historico';

    public const UPDATED_AT = null;

    protected $fillable = [
        'envio_id', 'status_anterior', 'status_novo', 'user_id', 'painel', 'estimado', 'ocorrido_em',
    ];

    protected $casts = [
        'estimado'    => 'boolean',
        'ocorrido_em' => 'datetime',
    ];

    public function envio()
    {
        return $this->belongsTo(Envio::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registra a mudança. Uma falha aqui nunca pode impedir a mudança de
     * status em si (ex.: deploy com a migration ainda não aplicada) — só é
     * reportada no log.
     */
    public static function registrar(Envio $envio, ?string $statusAnterior): void
    {
        try {
            static::create([
                'envio_id'        => $envio->id,
                'status_anterior' => $statusAnterior,
                'status_novo'     => $envio->status,
                'user_id'         => auth()->id(),
                'painel'          => self::painelAtual(),
                'estimado'        => false,
                'ocorrido_em'     => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Painel de quem fez a mudança, lido das abilities do token ("role:franquia"). */
    private static function painelAtual(): string
    {
        $token = auth()->user()?->currentAccessToken();
        if (!$token instanceof PersonalAccessToken) {
            return 'sistema';
        }

        $papel = collect($token->abilities ?? [])->first(fn($a) => str_starts_with($a, 'role:'));
        return $papel ? substr($papel, 5) : 'sistema';
    }
}
