<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceiroConfig extends Model
{
    protected $table    = 'financeiro_configs';
    protected $fillable = ['categoria', 'tipo_franquia', 'valor', 'created_by'];
    protected $casts    = ['valor' => 'float'];

    public const CATEGORIAS = [
        'mensalidade',
        'tx_royalties',
        'tx_marketing',
        'percentual_comissao',
        'percentual_imposto',
    ];
}
