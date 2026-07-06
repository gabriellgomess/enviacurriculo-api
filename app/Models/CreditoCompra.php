<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditoCompra extends Model
{
    protected $table = 'creditos_compras';

    protected $fillable = [
        'candidato_id',
        'pacote_id',
        'quantidade',
        'valor',
        'cpf',
        'nome',
        'status',
        'asaas_payment_id',
        'qr_code',
        'qr_code_image',
        'expiration_date',
        'paid_at',
    ];

    protected $casts = [
        'quantidade'       => 'integer',
        'valor'            => 'decimal:2',
        'expiration_date'  => 'datetime',
        'paid_at'          => 'datetime',
    ];

    public function candidato()
    {
        return $this->belongsTo(Candidato::class);
    }

    public function pacote()
    {
        return $this->belongsTo(CreditoPacote::class, 'pacote_id');
    }
}
