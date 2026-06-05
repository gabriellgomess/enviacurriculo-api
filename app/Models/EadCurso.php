<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EadCurso extends Model
{
    protected $table    = 'ead_cursos';
    protected $fillable = ['titulo', 'descricao', 'active'];
    protected $casts    = ['active' => 'boolean'];

    public function aulas() { return $this->hasMany(EadAula::class, 'curso_id'); }
}
