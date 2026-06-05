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
    ];

    protected $casts = [
        'visualizado_em' => 'datetime',
    ];

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
