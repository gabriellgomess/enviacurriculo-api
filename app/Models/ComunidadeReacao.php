<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComunidadeReacao extends Model
{
    protected $table = 'comunidade_reacoes';

    public $timestamps = false;

    protected $fillable = ['post_id', 'user_id', 'tipo'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
