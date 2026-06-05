<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParceiroAgendamento extends Model
{
    protected $table = 'parceiro_agendamentos';

    protected $fillable = [
        'parceiro_id',
        'cliente',
        'email',
        'telefone',
        'servico',
        'data',
        'duracao_min',
        'status',
        'observacao',
        'motivo_cancelamento',
    ];

    protected $casts = ['data' => 'datetime'];

    public function parceiro()
    {
        return $this->belongsTo(Parceiro::class);
    }
}
