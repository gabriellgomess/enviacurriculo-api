<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParceiroVisualizacao extends Model
{
    protected $table = 'parceiro_visualizacoes';

    protected $fillable = [
        'parceiro_id',
        'empresa_id',
        'empresa_nome',
        'usuario_nome',
        'telefone',
        'email',
        'tipo',
    ];

    public function parceiro()
    {
        return $this->belongsTo(Parceiro::class);
    }
}
