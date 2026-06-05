<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaFornecedor extends Model
{
    protected $table    = 'franquia_fornecedores';
    protected $fillable = ['franquia_id', 'nome', 'cnpj', 'email', 'telefone', 'categoria'];
}
