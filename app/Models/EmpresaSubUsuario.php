<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaSubUsuario extends Model
{
    protected $table = 'empresa_sub_usuarios';

    protected $fillable = [
        'empresa_id',
        'user_id',
        'menus_permitidos',
        'ativo',
    ];

    protected $casts = [
        'menus_permitidos' => 'array',
        'ativo'            => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
