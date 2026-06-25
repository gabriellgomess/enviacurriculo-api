<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnvioParecer extends Model
{
    protected $table    = 'envio_pareceres';
    protected $fillable = ['envio_id', 'texto', 'arquivo_path', 'arquivo_nome', 'autor', 'created_by', 'status', 'motivo_validacao'];

    public function envio() { return $this->belongsTo(Envio::class); }
}
