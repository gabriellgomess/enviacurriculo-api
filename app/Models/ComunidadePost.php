<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComunidadePost extends Model
{
    protected $table = 'comunidade_posts';

    protected $fillable = ['user_id', 'titulo', 'tipo', 'conteudo', 'imagem_url'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reacoes()
    {
        return $this->hasMany(ComunidadeReacao::class, 'post_id');
    }

    public function comentarios()
    {
        return $this->hasMany(ComunidadeComentario::class, 'post_id');
    }
}
