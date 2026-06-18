<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaEntrevista extends Model
{
    protected $table = 'empresa_entrevistas';

    protected $fillable = [
        'empresa_id',
        'candidato_id',
        'vaga_id',
        'data',
        'local',
        'modalidade',
        'link_video',
        'consultor_nome',
        'observacao',
        'status',
    ];

    protected $casts = [
        'data' => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function candidato()
    {
        return $this->belongsTo(Candidato::class);
    }

    public function vaga()
    {
        return $this->belongsTo(Vaga::class);
    }
}
