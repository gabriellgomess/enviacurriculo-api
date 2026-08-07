<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoFollowup extends Model
{
    protected $table = 'candidato_followups';

    protected $fillable = [
        'candidato_id',
        'franquia_id',
        'criado_por',
        'anotacao',
        'data_contato',
    ];

    protected $casts = [
        'data_contato' => 'date',
    ];

    public function candidato()
    {
        return $this->belongsTo(Candidato::class);
    }

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }

    public function autor()
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
