<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaFranquia extends Model
{
    protected $table    = 'metas_franquias';
    protected $fillable = [
        'franquia_id', 'tipo_meta_id', 'titulo', 'descricao',
        'valor_meta', 'valor_atual', 'data_inicio', 'data_fim', 'status',
    ];
    protected $casts = [
        'valor_meta'  => 'float',
        'valor_atual' => 'float',
        'data_inicio' => 'date:Y-m-d',
        'data_fim'    => 'date:Y-m-d',
    ];

    public function franquia() { return $this->belongsTo(Franquia::class); }
    public function tipoMeta() { return $this->belongsTo(TipoMeta::class, 'tipo_meta_id'); }
}
