<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EadProvaQuestao extends Model
{
    protected $table = 'ead_prova_questoes';

    protected $fillable = [
        'prova_id',
        'pergunta',
        'opcao_a',
        'opcao_b',
        'opcao_c',
        'opcao_d',
        'resposta_correta',
        'ordem',
    ];

    public function prova()
    {
        return $this->belongsTo(EadProva::class, 'prova_id');
    }
}
