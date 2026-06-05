<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParceiroCategoria extends Model
{
    protected $table = 'parceiros_categorias';

    protected $fillable = ['nome', 'ordem'];

    public function servicos()
    {
        return $this->hasMany(ParceiroServico::class, 'categoria_id');
    }
}
