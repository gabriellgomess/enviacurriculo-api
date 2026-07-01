<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContatoSite extends Model
{
    protected $table = 'contatos_site';

    protected $fillable = [
        'nome_completo',
        'telefone',
        'email',
        'mensagem',
        'status',
    ];
}
