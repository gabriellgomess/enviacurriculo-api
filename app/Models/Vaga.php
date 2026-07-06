<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vaga extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'codigo',
        'titulo',
        'descricao',
        'requisitos',
        'beneficios',
        'empresa_id',
        'franquia_id',
        'nivel_vaga_id',
        'taxa_servico',
        'tipo_contrato',
        'regime_trabalho',
        'carga_horaria',
        'salario_min',
        'salario_max',
        'exibir_salario',
        'cep',
        'cidade',
        'estado',
        'bairro',
        'quantidade_vagas',
        'status',
        'requer_validacao_premium',
        'data_abertura',
        'data_fechamento',
        'canal',
        'ocultar_empresa',
        'ocultar_endereco',
        'genero',
        'turno',
        'horario_trabalho',
        'nome_requisitante',
        'email_requisitante',
        'logradouro',
        'numero',
    ];

    protected $casts = [
        'exibir_salario'  => 'boolean',
        'salario_min'     => 'float',
        'salario_max'     => 'float',
        'quantidade_vagas'=> 'integer',
        'data_abertura'   => 'date',
        'data_fechamento' => 'date',
        'ocultar_empresa' => 'boolean',
        'ocultar_endereco'=> 'boolean',
        'requer_validacao_premium' => 'boolean',
    ];

    protected $appends = ['modalidade', 'salario_oculto'];

    // Aliases usados pelo painel do candidato
    public function getModalidadeAttribute(): ?string
    {
        return $this->regime_trabalho;
    }

    public function getSalarioOcultoAttribute(): bool
    {
        return ! (bool) $this->exibir_salario;
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }

    public function nivelVaga()
    {
        return $this->belongsTo(NivelVaga::class);
    }

    public function envios()
    {
        return $this->hasMany(Envio::class);
    }

    public function documentos()
    {
        return $this->hasMany(VagaDocumento::class);
    }

    public function franquiasCompartilhadas()
    {
        return $this->belongsToMany(Franquia::class, 'vaga_franquia_compartilhada', 'vaga_id', 'franquia_id')->withTimestamps();
    }

    public function beneficiosCatalogo()
    {
        return $this->belongsToMany(BeneficioCatalogo::class, 'vaga_beneficios', 'vaga_id', 'beneficio_id')->withTimestamps();
    }
}
