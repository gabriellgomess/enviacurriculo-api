<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaLead extends Model
{
    protected $table    = 'franquia_leads';
    protected $fillable = [
        'nome_completo', 'email', 'telefone', 'experiencia_rh',
        'bairro', 'cidade', 'estado', 'capital_disponivel', 'capital_confirmado',
        'tempo_inicio', 'motivacao', 'indicacao', 'status', 'observacoes',
    ];
    protected $casts = [
        'experiencia_rh'     => 'boolean',
        'capital_confirmado' => 'boolean',
    ];

    public function discConvites() { return $this->hasMany(DiscConvite::class, 'lead_id'); }
}
