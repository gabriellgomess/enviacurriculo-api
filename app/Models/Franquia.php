<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Franquia extends Model
{
    use SoftDeletes;

    protected $fillable = [
        // Identificação
        'codigo',
        'tipo',
        // Dados pessoais do franqueado
        'nome',
        'cpf',
        'data_nascimento',
        'responsavel',
        'email',
        'email_franqueado',
        'telefone',
        'data_inicio_parceria',
        'data_termino_parceria',
        // Endereço pessoal
        'cep',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'estado',
        'latitude',
        'longitude',
        // Dados da empresa
        'cnpj',
        'descricao',
        'cep_empresa',
        'logradouro_empresa',
        'numero_empresa',
        'complemento_empresa',
        'bairro_empresa',
        'cidade_empresa',
        'estado_empresa',
        'latitude_empresa',
        'longitude_empresa',
        // Dados bancários
        'nome_banco',
        'codigo_banco',
        'agencia',
        'numero_conta',
        'tipo_conta',
        'chave_pix',
        'logo_url',
        // Permissões e status
        'menus_permitidos',
        'active',
        'created_by',
    ];

    protected $casts = [
        'active'               => 'boolean',
        'latitude'             => 'float',
        'longitude'            => 'float',
        'latitude_empresa'     => 'float',
        'longitude_empresa'    => 'float',
        'data_nascimento'      => 'date',
        'data_inicio_parceria' => 'date',
        'data_termino_parceria'=> 'date',
        'menus_permitidos'     => 'array',
    ];

    public function usuarios()
    {
        return $this->hasManyThrough(
            User::class,
            UserContext::class,
            'context_id',
            'id',
            'id',
            'user_id'
        )->where('user_contexts.role', 'franquia');
    }

    public function documentos()
    {
        return $this->hasMany(FranquiaDocumento::class);
    }

    // Retorna sempre a URL completa, independente de como foi gravado
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $value
                ? (str_starts_with($value, 'http') ? $value : Storage::disk('public')->url($value))
                : null,
        );
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user()
    {
        return UserContext::where('role', 'franquia')
            ->where('context_id', $this->id)
            ->with('user')
            ->first()
            ?->user;
    }
}
