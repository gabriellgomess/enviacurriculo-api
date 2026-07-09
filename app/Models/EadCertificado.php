<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EadCertificado extends Model
{
    protected $table = 'ead_certificados';

    protected $fillable = [
        'franquia_id',
        'user_id',
        'curso_id',
    ];

    public function franquia()
    {
        return $this->belongsTo(Franquia::class, 'franquia_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function curso()
    {
        return $this->belongsTo(EadCurso::class, 'curso_id');
    }
}
