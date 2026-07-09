<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EadProvaResposta extends Model
{
    protected $table = 'ead_prova_respostas';

    protected $fillable = [
        'franquia_id',
        'user_id',
        'prova_id',
        'respostas',
        'nota',
        'aprovado',
    ];

    protected $casts = [
        'respostas' => 'array',
        'aprovado'  => 'boolean',
    ];

    public function franquia()
    {
        return $this->belongsTo(Franquia::class, 'franquia_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function prova()
    {
        return $this->belongsTo(EadProva::class, 'prova_id');
    }
}
