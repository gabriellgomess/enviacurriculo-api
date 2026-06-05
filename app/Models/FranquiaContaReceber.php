<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranquiaContaReceber extends Model
{
    protected $table = 'franquia_contas_receber';

    protected $fillable = [
        'franquia_id', 'candidato_nome', 'vaga_nome', 'empresa_nome', 'franchise_nome',
        'salario', 'taxa_servico', 'valor_bruto', 'imposto_perc', 'imposto_valor',
        'royalties_perc', 'royalties_valor', 'marketing_perc', 'marketing_valor',
        'comissao_perc', 'comissao_valor', 'comissao_s_start_perc', 'comissao_s_start_valor',
        'valor_liquido', 'data_faturamento', 'data_vencimento', 'data_reposicao',
        'is_sstart', 'status',
    ];

    protected $casts = [
        'is_sstart'        => 'boolean',
        'data_faturamento' => 'date',
        'data_vencimento'  => 'date',
        'data_reposicao'   => 'date',
    ];

    public function franquia() { return $this->belongsTo(Franquia::class); }
}
