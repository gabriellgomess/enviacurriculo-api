<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaTaxaServico extends Model
{
    protected $table = 'empresa_taxas_servico';

    protected $fillable = ['empresa_id', 'nivel_vaga_id', 'percentual'];

    protected $casts = ['percentual' => 'float'];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function nivelVaga()
    {
        return $this->belongsTo(NivelVaga::class, 'nivel_vaga_id');
    }
}
