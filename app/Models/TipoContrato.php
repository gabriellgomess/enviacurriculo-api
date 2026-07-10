<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoContrato extends Model
{
    protected $table = 'tipos_contrato';

    protected $fillable = [
        'nome',
        'slug',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
