<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComunidadeComentario extends Model
{
    protected $table = 'comunidade_comentarios';

    public $timestamps = false;

    protected $fillable = ['post_id', 'user_id', 'conteudo'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
