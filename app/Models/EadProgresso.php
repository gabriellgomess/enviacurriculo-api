<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EadProgresso extends Model
{
    protected $table    = 'ead_progresso';
    protected $fillable = ['franquia_id', 'aula_id', 'concluida', 'concluida_em'];
    protected $casts    = ['concluida' => 'boolean', 'concluida_em' => 'datetime'];
}
