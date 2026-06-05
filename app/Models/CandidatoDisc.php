<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoDisc extends Model
{
    protected $table = 'candidato_disc';

    protected $fillable = ['candidato_id', 'aplicado_por', 'perfil_dominante', 'score_d', 'score_i', 'score_s', 'score_c'];

    public function candidato() { return $this->belongsTo(Candidato::class); }
    public function aplicador() { return $this->belongsTo(User::class, 'aplicado_por'); }
}
