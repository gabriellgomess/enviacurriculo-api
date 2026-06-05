<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaAgendaEvento extends Model
{
    protected $table    = 'franquia_agenda_eventos';
    protected $fillable = [
        'franquia_id', 'titulo', 'descricao', 'data_inicio', 'data_fim',
        'tipo', 'local', 'candidato_id', 'empresa_id', 'vaga_id',
    ];
    protected $casts    = ['data_inicio' => 'datetime', 'data_fim' => 'datetime'];
}
