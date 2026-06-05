<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VagaDocumento extends Model
{
    protected $table = 'vaga_documentos';

    protected $fillable = [
        'vaga_id',
        'arquivo_path',
        'arquivo_nome',
        'tamanho_kb',
    ];

    public function vaga()
    {
        return $this->belongsTo(Vaga::class);
    }
}
