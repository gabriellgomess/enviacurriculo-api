<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaArquivo extends Model
{
    protected $table = 'empresa_arquivos';

    protected $fillable = [
        'empresa_id',
        'franquia_id',
        'arquivo_path',
        'arquivo_nome',
        'tamanho_kb',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }
}
