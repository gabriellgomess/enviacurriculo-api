<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NivelVaga extends Model
{
    protected $table = 'niveis_vagas';

    protected $fillable = ['nome', 'ordem'];

    public function taxasServico()
    {
        return $this->hasMany(EmpresaTaxaServico::class);
    }
}
