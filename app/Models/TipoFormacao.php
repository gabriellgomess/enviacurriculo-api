<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoFormacao extends Model
{
    protected $table = 'tipos_formacao';

    protected $fillable = ['nome'];
}
