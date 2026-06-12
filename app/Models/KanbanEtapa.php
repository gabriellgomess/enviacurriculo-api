<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KanbanEtapa extends Model
{
    protected $table    = 'kanban_etapas';
    protected $fillable = ['empresa_id', 'nome', 'cor', 'ordem', 'etapa_sistema'];
}
