<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmailOptOut extends Model
{
    protected $table    = 'email_opt_outs';
    protected $fillable = ['email', 'token', 'unsubscribed_at'];
    protected $casts    = ['unsubscribed_at' => 'datetime'];

    /**
     * Retorna (criando se necessário) o token de unsubscribe de um e-mail.
     * Use ao montar links nos e-mails enviados:
     *   {FRONTEND_HOME}/unsubscribe?token={EmailOptOut::tokenFor($email)}
     */
    public static function tokenFor(string $email): string
    {
        $registro = static::firstOrCreate(
            ['email' => strtolower($email)],
            ['token' => Str::random(64)],
        );

        return $registro->token;
    }

    /**
     * Verifica se um e-mail está descadastrado (use antes de enviar e-mails).
     */
    public static function isUnsubscribed(string $email): bool
    {
        return static::where('email', strtolower($email))
            ->whereNotNull('unsubscribed_at')
            ->exists();
    }
}
