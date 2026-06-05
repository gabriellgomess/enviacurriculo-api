<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaManual extends Model
{
    protected $table    = 'franquia_manuais';
    protected $fillable = ['titulo', 'arquivo_path', 'arquivo_nome', 'tamanho_kb', 'active'];
    protected $casts    = ['active' => 'boolean'];
}
