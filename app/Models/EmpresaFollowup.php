<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaFollowup extends Model
{
    protected $table = 'empresa_followups';

    protected $fillable = [
        'empresa_id', 'user_id', 'user_name', 'user_type', 'mensagem',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
