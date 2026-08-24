<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Envio extends Model
{
    protected $table = 'envios';

    protected $fillable = [
        'candidato_id',
        'vaga_id',
        'franquia_id',
        'curriculo_id',
        'mensagem',
        'status',
        'visualizado_em',
        'kanban_etapa_id',
        'origem',
        'status_empresa',
        'observacao',
        'salario_aprovado',
        'tipo_contrato',
        'data_admissao',
        'data_saida',
    ];

    protected $casts = [
        'visualizado_em'   => 'datetime',
        'salario_aprovado' => 'float',
        'data_admissao'    => 'date:Y-m-d',
        'data_saida'       => 'date:Y-m-d',
    ];

    /**
     * O processo seletivo é o MESMO registro para a franquia e para a empresa,
     * mas cada painel tem seu vocabulário de status:
     *
     *   envios.status         → usado pela franquia e visto pelo candidato
     *   envios.status_empresa → usado pelo painel da empresa
     *
     * Os dois precisam andar juntos: alteração em um lado reflete no outro.
     * Os métodos abaixo concentram essa tradução para não haver duas regras
     * divergentes espalhadas pelos controllers.
     */

    /** Status da franquia → status equivalente no painel da empresa. */
    public static function statusEmpresaPara(string $status): string
    {
        return match ($status) {
            'enviado', 'visualizado' => 'pendente',
            'em_entrevista'          => 'em_processo',
            default                  => $status, // em_processo, pendente, aprovado, reprovado, desistiu, reposicao
        };
    }

    /**
     * Status da empresa → status equivalente para franquia/candidato.
     *
     * "pendente" existe só no vocabulário da empresa. Do lado da franquia o
     * mesmo estado se chama `enviado` — e é assim que a interface o exibe, com
     * o rótulo "Pendente". Gravar `pendente` aqui criava dois valores para a
     * mesma etapa, que era o que os relatórios mostravam em selos separados.
     */
    public static function statusFranquiaPara(string $statusEmpresa, ?string $statusAtual = null): string
    {
        return match ($statusEmpresa) {
            // Não rebaixa um envio que a franquia já marcou como visualizado.
            'pendente' => $statusAtual === 'visualizado' ? 'visualizado' : 'enviado',
            default    => $statusEmpresa,
        };
    }

    public function kanbanEtapa()
    {
        return $this->belongsTo(KanbanEtapa::class);
    }

    public function pareceres()
    {
        return $this->hasMany(EnvioParecer::class);
    }

    public function candidato()
    {
        return $this->belongsTo(Candidato::class);
    }

    public function vaga()
    {
        return $this->belongsTo(Vaga::class);
    }

    /**
     * Franquia que registrou o encaminhamento.
     *
     * Não é a dona da vaga: a vaga pode ser da Matriz e o envio ser de uma
     * franquia convidada. Nulo quando o envio veio da operação central.
     */
    public function franquia()
    {
        return $this->belongsTo(Franquia::class);
    }

    public function curriculo()
    {
        return $this->belongsTo(CandidatoDocumento::class, 'curriculo_id');
    }
}
