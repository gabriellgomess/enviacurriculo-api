<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditoMovimentacao extends Model
{
    protected $table = 'creditos_movimentacoes';

    protected $fillable = [
        'candidato_id',
        'tipo',
        'quantidade',
        'saldo_antes',
        'saldo_depois',
        'descricao',
        'referencia_tipo',
        'referencia_id',
    ];

    protected $casts = [
        'quantidade'   => 'integer',
        'saldo_antes'  => 'integer',
        'saldo_depois' => 'integer',
    ];

    public function candidato()
    {
        return $this->belongsTo(Candidato::class);
    }
}
