<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParceiroServico extends Model
{
    protected $table = 'parceiros_servicos';

    protected $fillable = [
        'parceiro_id',
        'categoria_id',
        'nome_servico',
        'descricao',
        'proposta_url',
    ];

    public function parceiro()
    {
        return $this->belongsTo(Parceiro::class);
    }

    public function categoria()
    {
        return $this->belongsTo(ParceiroCategoria::class, 'categoria_id');
    }
}
