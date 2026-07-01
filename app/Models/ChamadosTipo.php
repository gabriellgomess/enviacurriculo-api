<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChamadosTipo extends Model
{
    protected $table = 'chamados_tipos';

    protected $fillable = ['nome', 'descricao'];
}
