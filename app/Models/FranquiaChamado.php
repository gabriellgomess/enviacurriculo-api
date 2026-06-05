<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaChamado extends Model
{
    protected $table = 'franquia_chamados';

    protected $fillable = ['franquia_id', 'titulo', 'descricao', 'categoria', 'prioridade', 'status', 'resposta'];

    public function mensagens() { return $this->hasMany(FranquiaChamadoMensagem::class, 'chamado_id'); }
    public function franquia()  { return $this->belongsTo(Franquia::class); }
}
