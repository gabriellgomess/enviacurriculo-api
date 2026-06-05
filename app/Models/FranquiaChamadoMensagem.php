<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaChamadoMensagem extends Model
{
    protected $table    = 'franquia_chamado_mensagens';
    public    $timestamps = false;

    protected $fillable = ['chamado_id', 'mensagem', 'autor'];

    protected $casts = ['created_at' => 'datetime'];

    public function chamado() { return $this->belongsTo(FranquiaChamado::class); }
}
