<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Envio extends Model
{
    protected $table = 'envios';

    protected $fillable = [
        'candidato_id',
        'vaga_id',
        'curriculo_id',
        'mensagem',
        'status',
        'visualizado_em',
        'kanban_etapa_id',
        'origem',
        'status_empresa',
        'observacao',
        'salario_aprovado',
        'data_admissao',
        'data_saida',
    ];

    protected $casts = [
        'visualizado_em'   => 'datetime',
        'salario_aprovado' => 'float',
        'data_admissao'    => 'date:Y-m-d',
        'data_saida'       => 'date:Y-m-d',
    ];

    public function kanbanEtapa()
    {
        return $this->belongsTo(KanbanEtapa::class);
    }

    public function pareceres()
    {
        return $this->hasMany(EnvioParecer::class);
    }

    public function candidato()
    {
        return $this->belongsTo(Candidato::class);
    }

    public function vaga()
    {
        return $this->belongsTo(Vaga::class);
    }

    public function curriculo()
    {
        return $this->belongsTo(CandidatoDocumento::class, 'curriculo_id');
    }
}
