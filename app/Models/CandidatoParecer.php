<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoParecer extends Model
{
    protected $table = 'candidato_pareceres';

    protected $fillable = ['franquia_id', 'candidato_id', 'vaga_id', 'empresa_id', 'criado_por', 'texto', 'nota', 'status_aprovacao'];

    public function candidato() { return $this->belongsTo(Candidato::class); }
    public function vaga()      { return $this->belongsTo(Vaga::class); }
    public function empresa()   { return $this->belongsTo(Empresa::class); }
    public function criador()   { return $this->belongsTo(User::class, 'criado_por'); }
    public function franquia()  { return $this->belongsTo(Franquia::class); }
}
