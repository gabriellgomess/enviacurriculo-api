<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KanbanTipo extends Model
{
    protected $table = 'kanban_tipos';

    protected $fillable = ['nome', 'descricao'];
}
