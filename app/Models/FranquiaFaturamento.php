<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaFaturamento extends Model
{
    protected $table    = 'franquia_faturamentos';
    protected $fillable = ['franquia_id', 'descricao', 'tipo', 'valor', 'status', 'data_referencia', 'data_pagamento', 'empresa_nome'];
    protected $casts    = ['data_referencia' => 'date', 'data_pagamento' => 'date', 'valor' => 'float'];

    public function franquia() { return $this->belongsTo(Franquia::class); }
}
