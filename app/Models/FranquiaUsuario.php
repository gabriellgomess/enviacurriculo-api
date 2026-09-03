<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaUsuario extends Model
{
    protected $table = 'franquia_usuarios';

    protected $fillable = [
        'franquia_id',
        'user_id',
        'tipo',
        'cargo',
        'ativo',
        'created_by',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
