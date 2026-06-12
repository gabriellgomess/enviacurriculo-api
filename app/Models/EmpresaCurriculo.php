<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaCurriculo extends Model
{
    protected $table    = 'empresa_curriculos';
    protected $fillable = [
        'empresa_id', 'candidato_id', 'kanban_etapa_id',
        'nome', 'email', 'telefone', 'cpf', 'cargo_desejado',
        'cidade', 'estado', 'origem', 'arquivo_path', 'arquivo_nome',
    ];

    public function candidato()   { return $this->belongsTo(Candidato::class); }
    public function kanbanEtapa() { return $this->belongsTo(KanbanEtapa::class); }
}
