<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotaFiscal extends Model
{
    protected $table    = 'admin_notas_fiscais';
    protected $fillable = [
        'tipo', 'numero', 'razao_social', 'cnpj_cpf', 'valor',
        'data_emissao', 'data_vencimento', 'descricao', 'status',
        'arquivo_path', 'arquivo_nome', 'created_by',
    ];
    protected $casts = [
        'valor'           => 'float',
        'data_emissao'    => 'date:Y-m-d',
        'data_vencimento' => 'date:Y-m-d',
    ];
}
