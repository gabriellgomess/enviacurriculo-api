<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EadAula extends Model
{
    protected $table    = 'ead_aulas';
    protected $fillable = ['curso_id', 'modulo', 'titulo', 'video_url', 'duracao_minutos', 'ordem'];

    public function curso()    { return $this->belongsTo(EadCurso::class); }
    public function progresso(){ return $this->hasMany(EadProgresso::class, 'aula_id'); }
}
