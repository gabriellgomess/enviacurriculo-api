<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgendaTarefa extends Model
{
    protected $table = 'agenda_tarefas';

    protected $fillable = [
        'user_id',
        'titulo',
        'descricao',
        'data_tarefa',
        'hora',
        'concluida',
    ];

    protected $casts = [
        'concluida' => 'boolean',
        'data_tarefa' => 'date:Y-m-d',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
