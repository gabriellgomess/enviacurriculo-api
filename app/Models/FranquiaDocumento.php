<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaDocumento extends Model
{
    protected $table = 'franquia_documentos';

    protected $fillable = [
        'franquia_id',
        'tipo',
        'arquivo_path',
        'arquivo_nome',
        'tamanho_kb',
    ];

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }
}
