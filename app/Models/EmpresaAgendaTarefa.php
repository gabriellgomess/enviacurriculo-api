<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaAgendaTarefa extends Model
{
    protected $table = 'empresa_agenda_tarefas';

    protected $fillable = [
        'empresa_id',
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

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}
