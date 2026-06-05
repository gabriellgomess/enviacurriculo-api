<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminPermission extends Model
{
    protected $fillable = ['user_id', 'acesso_total', 'menus_permitidos'];

    protected $casts = [
        'acesso_total'    => 'boolean',
        'menus_permitidos' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
