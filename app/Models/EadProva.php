<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EadProva extends Model
{
    protected $table = 'ead_provas';

    protected $fillable = [
        'curso_id',
        'titulo',
        'nota_minima',
    ];

    public function curso()
    {
        return $this->belongsTo(EadCurso::class, 'curso_id');
    }

    public function questoes()
    {
        return $this->hasMany(EadProvaQuestao::class, 'prova_id')->orderBy('ordem');
    }
}
