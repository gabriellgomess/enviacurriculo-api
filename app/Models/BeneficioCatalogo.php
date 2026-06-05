<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BeneficioCatalogo extends Model
{
    protected $table = 'beneficios_catalogo';

    protected $fillable = ['nome', 'icone', 'categoria', 'is_sistema'];

    protected $casts = ['is_sistema' => 'boolean'];
}
