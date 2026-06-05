<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaContaPagar extends Model
{
    protected $table = 'franquia_contas_pagar';

    protected $fillable = [
        'franquia_id', 'descricao', 'valor', 'data_vencimento', 'data_pagamento',
        'categoria', 'status', 'fornecedor_nome', 'observacao',
    ];

    protected $casts = ['data_vencimento' => 'date', 'data_pagamento' => 'date', 'valor' => 'float'];

    public function franquia() { return $this->belongsTo(Franquia::class); }
}
