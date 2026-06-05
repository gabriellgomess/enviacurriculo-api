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
        'data_abertura',
        'data_fechamento',
    ];

    protected $casts = [
        'exibir_salario'  => 'boolean',
        'salario_min'     => 'float',
        'salario_max'     => 'float',
        'quantidade_vagas'=> 'integer',
        'data_abertura'   => 'date',
        'data_fechamento' => 'date',
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
}
