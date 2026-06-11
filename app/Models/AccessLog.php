<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    public $timestamps  = false; // só created_at, com default no banco
    protected $table    = 'access_logs';
    protected $fillable = ['user_id', 'user_name', 'user_email', 'user_type', 'action', 'created_at'];
    protected $casts    = ['created_at' => 'datetime'];

    public static function record($user, string $userType, string $action): void
    {
        try {
            static::create([
                'user_id'    => $user->id,
                'user_name'  => $user->name,
                'user_email' => $user->email,
                'user_type'  => $userType,
                'action'     => $action,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // log de acesso nunca deve quebrar o fluxo de autenticação
        }
    }
}
