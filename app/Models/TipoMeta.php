<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoMeta extends Model
{
    protected $table    = 'tipos_metas';
    protected $fillable = ['nome', 'descricao', 'unidade'];

    public function metas() { return $this->hasMany(MetaFranquia::class, 'tipo_meta_id'); }
}
