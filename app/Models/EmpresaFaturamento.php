<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaFaturamento extends Model
{
    protected $table = 'empresa_faturamentos';

    protected $fillable = ['empresa_id', 'mes', 'ano', 'valor'];

    protected $casts = [
        'mes'   => 'integer',
        'ano'   => 'integer',
        'valor' => 'float',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}
