<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditoPacote extends Model
{
    protected $table = 'creditos_pacotes';

    protected $fillable = [
        'nome',
        'label',
        'quantidade',
        'preco',
        'destaque',
        'ordem',
        'active',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'preco'      => 'decimal:2',
        'destaque'   => 'boolean',
        'ordem'      => 'integer',
        'active'     => 'boolean',
    ];
}
