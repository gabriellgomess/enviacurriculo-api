<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Parceiro extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'franquia_id',
        'razao_social',
        'nome_empresa',
        'cnpj',
        'categoria',
        'descricao',
        'telefone',
        'email',
        'logo_url',
        'cep',
        'rua',
        'numero',
        'bairro',
        'cidade',
        'estado',
        'especialidades',
        'active',
    ];

    protected $casts = [
        'active'        => 'boolean',
        'especialidades' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }

    public function servicos()
    {
        return $this->hasMany(ParceiroServico::class);
    }
}
