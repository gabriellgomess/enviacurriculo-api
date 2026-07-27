<?php

namespace App\Services;

use App\Models\CandidatoParecer;
use App\Models\EmpresaEntrevista;
use App\Models\TesteAgendado;
use Illuminate\Database\Eloquent\Model;

/**
 * Mantém sincronizados os agendamentos informados no PARECER (franquia/admin)
 * e as telas da EMPRESA:
 *
 *   dados.teste_empresa_data / _local       ↔  testes_agendados     (tela Testes)
 *   dados.entrevista_empresa_data / _local  ↔  empresa_entrevistas  (tela Entrevistas)
 *
 * O vínculo é feito pela coluna `parecer_id` das duas tabelas. Registros
 * criados manualmente pela empresa têm parecer_id nulo e nunca são tocados.
 *
 * A sincronização é idempotente e silenciosa: sem empresa vinculada ao parecer
 * ou sem data preenchida, nada é criado.
 */
class SincronizacaoAgendamentosParecer
{
    /** Evita que um lado dispare o outro em laço. */
    private static bool $sincronizando = false;

    /**
     * Configuração de cada agendamento sincronizado.
     * Mantém teste e entrevista com exatamente a mesma regra.
     */
    private const MAPA = [
        'teste' => [
            'model'       => TesteAgendado::class,
            'campo_data'  => 'teste_empresa_data',
            'campo_local' => 'teste_empresa_local',
            'status'      => 'agendado',
            'extras'      => ['tipo_teste' => 'pratico'],
            'observacao'  => 'Agendado pela franquia no parecer do candidato.',
        ],
        'entrevista' => [
            'model'       => EmpresaEntrevista::class,
            'campo_data'  => 'entrevista_empresa_data',
            'campo_local' => 'entrevista_empresa_local',
            'status'      => 'agendada',
            'extras'      => ['modalidade' => 'presencial'],
            'observacao'  => 'Agendada pela franquia no parecer do candidato.',
        ],
    ];

    /**
     * Parecer criado/atualizado → cria, atualiza ou remove o teste e a
     * entrevista correspondentes no painel da empresa.
     */
    public function doParecer(CandidatoParecer $parecer): void
    {
        if (self::$sincronizando) {
            return;
        }

        foreach (self::MAPA as $cfg) {
            $this->sincronizarUm($parecer, $cfg);
        }
    }

    private function sincronizarUm(CandidatoParecer $parecer, array $cfg): void
    {
        $dados = $parecer->dados ?? [];
        $data  = $dados[$cfg['campo_data']]  ?? null;
        $local = $dados[$cfg['campo_local']] ?? null;

        /** @var class-string<Model> $classe */
        $classe = $cfg['model'];

        $registro = $classe::where('parecer_id', $parecer->id)->first();

        // Sem data ou sem empresa: não há o que exibir para a empresa.
        if (empty($data) || empty($parecer->empresa_id)) {
            $registro?->delete();
            return;
        }

        self::$sincronizando = true;

        try {
            if ($registro) {
                $registro->update([
                    'data'       => $data,
                    'local'      => $local,
                    'empresa_id' => $parecer->empresa_id,
                    'vaga_id'    => $parecer->vaga_id,
                ]);

                return;
            }

            $classe::create([
                'empresa_id'   => $parecer->empresa_id,
                'candidato_id' => $parecer->candidato_id,
                'vaga_id'      => $parecer->vaga_id,
                'parecer_id'   => $parecer->id,
                'data'         => $data,
                'local'        => $local,
                'status'       => $cfg['status'],
                'observacao'   => $cfg['observacao'],
                ...$cfg['extras'],
            ]);
        } finally {
            self::$sincronizando = false;
        }
    }

    /**
     * Teste/entrevista alterado pela empresa → grava de volta no parecer.
     */
    public function doTeste(Model $registro): void
    {
        $this->escreverNoParecer($registro, limpar: false);
    }

    /** Alias explícito para o lado das entrevistas. */
    public function doEntrevista(Model $registro): void
    {
        $this->escreverNoParecer($registro, limpar: false);
    }

    /**
     * Registro excluído pela empresa → limpa a informação no parecer de
     * origem, para os dois lados não ficarem divergentes.
     */
    public function aoExcluirTeste(Model $registro): void
    {
        $this->escreverNoParecer($registro, limpar: true);
    }

    /** Alias explícito para o lado das entrevistas. */
    public function aoExcluirEntrevista(Model $registro): void
    {
        $this->escreverNoParecer($registro, limpar: true);
    }

    private function escreverNoParecer(Model $registro, bool $limpar): void
    {
        if (self::$sincronizando || empty($registro->parecer_id)) {
            return;
        }

        $cfg = $registro instanceof EmpresaEntrevista
            ? self::MAPA['entrevista']
            : self::MAPA['teste'];

        $parecer = CandidatoParecer::find($registro->parecer_id);
        if (!$parecer) {
            return;
        }

        self::$sincronizando = true;

        try {
            $dados = $parecer->dados ?? [];

            $dados[$cfg['campo_data']]  = $limpar ? null : optional($registro->data)->toDateTimeString();
            $dados[$cfg['campo_local']] = $limpar ? null : $registro->local;

            $parecer->update(['dados' => $dados]);
        } finally {
            self::$sincronizando = false;
        }
    }
}
