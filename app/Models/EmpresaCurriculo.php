<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaCurriculo extends Model
{
    protected $table    = 'empresa_curriculos';
    protected $fillable = [
        'empresa_id', 'candidato_id', 'kanban_etapa_id',
        'nome', 'email', 'telefone', 'cpf', 'cargo_desejado',
        'cidade', 'estado', 'bairro', 'cargos_interesse',
        'experiencia_profissional', 'educacao', 'habilidades',
        'origem', 'arquivo_path', 'arquivo_nome',
        'arquivo_cnh_path', 'arquivo_cnh_nome',
        'arquivo_ctps_path', 'arquivo_ctps_nome', 'diplomas',
        'cep', 'rua', 'numero', 'complemento', 'tipo_cnh',
        'informacoes_pessoais', 'idiomas', 'informacoes_adicionais', 'active',
    ];

    protected $casts = [
        'cargos_interesse' => 'array',
        'diplomas'         => 'array',
        'active'           => 'boolean',
    ];

    public function candidato()   { return $this->belongsTo(Candidato::class); }
    public function kanbanEtapa() { return $this->belongsTo(KanbanEtapa::class); }
}
