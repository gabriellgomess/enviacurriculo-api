<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParceiroTarefa extends Model
{
    protected $table = 'parceiro_tarefas';

    protected $fillable = [
        'parceiro_id',
        'titulo',
        'descricao',
        'data_tarefa',
        'hora',
        'concluida',
    ];

    protected $casts = [
        'data_tarefa' => 'date:Y-m-d',
        'concluida'   => 'boolean',
    ];

    public function parceiro()
    {
        return $this->belongsTo(Parceiro::class);
    }
}
