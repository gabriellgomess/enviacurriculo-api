<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empresa extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'codigo',
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'email',
        'telefone',
        'tipo_empresa',
        'tipo_acesso',
        'plano',
        'status',
        'descricao',
        'cep',
        'rua',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'estado',
        'latitude',
        'longitude',
        'prazo_vencimento_dias',
        'reposicao_dias',
        'franquia_id',
        'active',
        'logo_url',
    ];

    protected $casts = [
        'active'               => 'boolean',
        'prazo_vencimento_dias'=> 'integer',
        'reposicao_dias'       => 'integer',
        'latitude'             => 'float',
        'longitude'            => 'float',
    ];

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }

    public function followups()
    {
        return $this->hasMany(EmpresaFollowup::class)->orderBy('created_at');
    }

    public function taxasServico()
    {
        return $this->hasMany(EmpresaTaxaServico::class)->with('nivelVaga');
    }

    public function usuarios()
    {
        return $this->hasManyThrough(
            User::class,
            UserContext::class,
            'context_id',
            'id',
            'id',
            'user_id'
        )->where('user_contexts.role', 'empresa');
    }

    public function beneficios()
    {
        return $this->belongsToMany(BeneficioCatalogo::class, 'empresa_beneficios', 'empresa_id', 'beneficio_id');
    }

    public function vagas()
    {
        return $this->hasMany(Vaga::class);
    }

    public function user()
    {
        return UserContext::where('role', 'empresa')
            ->where('context_id', $this->id)
            ->with('user')
            ->first()
            ?->user;
    }
}
