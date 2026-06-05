<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaServico extends Model
{
    protected $table    = 'franquia_servicos';
    protected $fillable = ['franquia_id', 'nome', 'descricao', 'valor_base', 'active'];
    protected $casts    = ['active' => 'boolean', 'valor_base' => 'float'];
}
