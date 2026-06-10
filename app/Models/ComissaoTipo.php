<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComissaoTipo extends Model
{
    protected $table    = 'comissao_tipos';
    protected $fillable = ['tipo', 'percentual'];
    protected $casts    = ['percentual' => 'float'];
}
