<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidato extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'franquia_id',
        'criado_por',
        'cpf',
        'nascimento',
        'telefone',
        'cep',
        'rua',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'estado',
        'tipo_cnh',
        'experiencia_profissional',
        'educacao',
        'habilidades',
        'idiomas',
        'informacoes_adicionais',
        'cargos_interesse',
        'cargo_desejado',
        'apresentacao',
        'linkedin',
        'github',
        'portfolio_url',
        'pretensao_salarial',
        'disponibilidade',
        'pcd',
        'latitude',
        'longitude',
        'foto_url',
        'creditos',
        'active',
    ];

    protected $appends = ['logradouro', 'foto'];

    protected $casts = [
        'nascimento'        => 'date',
        'active'            => 'boolean',
        'creditos'          => 'integer',
        'pcd'               => 'boolean',
        'pretensao_salarial'=> 'float',
        'latitude'          => 'float',
        'longitude'         => 'float',
        'cargos_interesse'  => 'array',
    ];

    public function getLogradouroAttribute(): ?string
    {
        return $this->rua;
    }

    public function getFotoAttribute(): ?string
    {
        return $this->foto_url;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }

    public function documentos()
    {
        return $this->hasMany(CandidatoDocumento::class);
    }

    public function envios()
    {
        return $this->hasMany(Envio::class);
    }

    public function movimentacoes()
    {
        return $this->hasMany(CreditoMovimentacao::class);
    }

    public function pareceres()
    {
        return $this->hasMany(CandidatoParecer::class);
    }
}
