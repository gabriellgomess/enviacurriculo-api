<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TesteAgendado extends Model
{
    protected $table    = 'testes_agendados';
    protected $fillable = [
        'empresa_id', 'candidato_id', 'vaga_envio_id', 'vaga_id',
        'tipo_teste', 'data', 'local', 'status', 'observacao',
    ];
    protected $casts = ['data' => 'datetime'];

    public function candidato() { return $this->belongsTo(Candidato::class); }
    public function vaga()      { return $this->belongsTo(Vaga::class); }
    public function envio()     { return $this->belongsTo(Envio::class, 'vaga_envio_id'); }
}
