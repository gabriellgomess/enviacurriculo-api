<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaArquivo extends Model
{
    protected $table    = 'franquia_arquivos';
    protected $fillable = ['franquia_id', 'nome', 'arquivo_path', 'arquivo_nome', 'tamanho_kb', 'categoria'];
}
