<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaColaborador extends Model
{
    protected $table = 'empresa_colaboradores';

    protected $fillable = [
        'empresa_id',
        'nome_completo',
        'cpf',
        'cargo',
        'departamento',
        'email',
        'telefone',
        'data_admissao',
        'data_nascimento',
        'salario',
        'status',
        'observacao',
    ];

    protected $casts = [
        'data_admissao'   => 'date:Y-m-d',
        'data_nascimento' => 'date:Y-m-d',
        'salario'         => 'decimal:2',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}
