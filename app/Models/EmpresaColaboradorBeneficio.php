<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaColaboradorBeneficio extends Model
{
    protected $table = 'empresa_colaborador_beneficios';

    protected $fillable = [
        'empresa_id',
        'nome',
        'descricao',
        'categoria',
        'valor',
        'ativo',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'ativo' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}
