<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoDocumento extends Model
{
    protected $table = 'candidato_documentos';

    protected $fillable = [
        'candidato_id',
        'tipo',
        'arquivo_path',
        'arquivo_nome',
        'tamanho_kb',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function candidato()
    {
        return $this->belongsTo(Candidato::class);
    }
}
