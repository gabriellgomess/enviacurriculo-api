<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaNotaFiscal extends Model
{
    protected $table    = 'franquia_notas_fiscais';
    protected $fillable = ['franquia_id', 'numero_nf', 'descricao', 'valor', 'status', 'data_emissao'];
    protected $casts    = ['data_emissao' => 'date', 'valor' => 'float'];
}
