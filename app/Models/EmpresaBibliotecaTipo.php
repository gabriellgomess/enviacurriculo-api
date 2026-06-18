<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaBibliotecaTipo extends Model
{
    protected $table = 'empresa_biblioteca_tipos';

    protected $fillable = ['empresa_id', 'nome'];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function documentos()
    {
        return $this->hasMany(EmpresaBibliotecaDocumento::class, 'tipo_id');
    }
}
