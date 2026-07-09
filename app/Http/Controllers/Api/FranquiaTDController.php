<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\EadAula;
use App\Models\EadCurso;
use App\Models\EadProgresso;
use App\Models\FranquiaOnboardingItem;
use App\Models\FranquiaOnboardingProgresso;
use Illuminate\Http\Request;

class FranquiaTDController extends Controller
{
    use HasTokenContext;

    // GET /franquia/td/onboarding
    public function onboarding(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $itens = FranquiaOnboardingItem::where('active', true)->orderBy('ordem')->get();

        $progresso = FranquiaOnboardingProgresso::where('franquia_id', $franquiaId)
            ->pluck('concluido', 'item_id');

        $data = $itens->map(fn($item) => [
            'id'        => $item->id,
            'titulo'    => $item->titulo,
            'descricao' => $item->descricao,
            'ordem'     => $item->ordem,
            'concluido' => (bool) ($progresso[$item->id] ?? false),
        ]);

        $concluidos = $data->where('concluido', true)->count();
        $total      = $data->count();

        return response()->json([
            'data'      => $data,
            'progresso' => [
                'concluidos' => $concluidos,
                'total'      => $total,
                'percentual' => $total > 0 ? round($concluidos / $total * 100) : 0,
            ],
        ]);
    }

    // PATCH /franquia/td/onboarding/{id}/concluir
    public function concluirOnboarding(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $item = FranquiaOnboardingItem::findOrFail($id);

        FranquiaOnboardingProgresso::updateOrCreate(
            ['franquia_id' => $franquiaId, 'item_id' => $item->id],
            ['concluido' => true, 'concluido_em' => now()]
        );

        return response()->json(['message' => 'Etapa concluída.', 'data' => ['id' => $id, 'concluido' => true]]);
    }

    // GET /franquia/td/ead
    public function ead(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $cursos = EadCurso::where('active', true)->with('aulas:id,curso_id')->get();

        $aulaIds = $cursos->flatMap(fn($c) => $c->aulas->pluck('id'));

        $progressoMap = EadProgresso::where('franquia_id', $franquiaId)
            ->whereIn('aula_id', $aulaIds)
            ->where('concluida', true)
            ->pluck('aula_id');

        $certificados = \App\Models\EadCertificado::where('franquia_id', $franquiaId)->pluck('curso_id');

        $data = $cursos->map(function ($curso) use ($progressoMap, $certificados) {
            $totalAulas     = $curso->aulas->count();
            $aulasConcluidas = $curso->aulas->filter(fn($a) => $progressoMap->contains($a->id))->count();

            return [
                'id'               => $curso->id,
                'titulo'           => $curso->titulo,
                'descricao'        => $curso->descricao,
                'total_aulas'      => $totalAulas,
                'duracao_minutos'  => $curso->aulas->sum('duracao_minutos'),
                'certificado'      => $certificados->contains($curso->id),
                'progresso'        => [
                    'aulas_concluidas' => $aulasConcluidas,
                    'percentual'       => $totalAulas > 0 ? round($aulasConcluidas / $totalAulas * 100) : 0,
                    'concluido'        => $totalAulas > 0 && $aulasConcluidas >= $totalAulas,
                ],
            ];
        });

        return response()->json(['data' => $data]);
    }

    // GET /franquia/td/ead/{id}
    public function eadShow(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $curso = EadCurso::with(['aulas', 'provas.questoes'])->where('active', true)->findOrFail($id);

        $progressoMap = EadProgresso::where('franquia_id', $franquiaId)
            ->whereIn('aula_id', $curso->aulas->pluck('id'))
            ->where('concluida', true)
            ->pluck('aula_id');

        $aulas = $curso->aulas->sortBy('ordem')->map(fn($a) => [
            'id'               => $a->id,
            'titulo'           => $a->titulo,
            'modulo'           => $a->modulo,
            'video_url'        => $a->video_url,
            'duracao_minutos'  => $a->duracao_minutos,
            'concluida'        => $progressoMap->contains($a->id),
        ]);

        $provas = $curso->provas->map(fn($p) => [
            'id'          => $p->id,
            'titulo'      => $p->titulo,
            'nota_minima' => $p->nota_minima,
            'questoes'    => $p->questoes->sortBy('ordem')->map(fn($q) => [
                'id'       => $q->id,
                'pergunta' => $q->pergunta,
                'opcao_a'  => $q->opcao_a,
                'opcao_b'  => $q->opcao_b,
                'opcao_c'  => $q->opcao_c,
                'opcao_d'  => $q->opcao_d,
                'ordem'    => $q->ordem,
            ])->values(),
        ]);

        $concluidas = $progressoMap->count();
        $total      = $curso->aulas->count();
        $certificado = \App\Models\EadCertificado::where('franquia_id', $franquiaId)->where('curso_id', $curso->id)->exists();

        return response()->json(['data' => [
            'id'          => $curso->id,
            'titulo'      => $curso->titulo,
            'descricao'   => $curso->descricao,
            'aulas'       => $aulas->values(),
            'provas'      => $provas->values(),
            'certificado' => $certificado,
            'progresso'   => [
                'aulas_concluidas' => $concluidas,
                'total'            => $total,
                'percentual'       => $total > 0 ? round($concluidas / $total * 100) : 0,
            ],
        ]]);
    }

    // POST /franquia/td/ead/{id}/progresso
    public function eadProgresso(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $request->validate([
            'aula_id'  => 'required|integer|exists:ead_aulas,id',
            'concluida'=> 'required|boolean',
        ]);

        // Valida que a aula pertence ao curso
        $aula = EadAula::where('curso_id', $id)->findOrFail($request->aula_id);

        EadProgresso::updateOrCreate(
            ['franquia_id' => $franquiaId, 'aula_id' => $aula->id],
            ['concluida' => $request->concluida, 'concluida_em' => $request->concluida ? now() : null]
        );

        // Recalcula progresso do curso
        $curso       = EadCurso::with('aulas:id,curso_id')->findOrFail($id);
        $total       = $curso->aulas->count();
        $concluidas  = EadProgresso::where('franquia_id', $franquiaId)
            ->whereIn('aula_id', $curso->aulas->pluck('id'))
            ->where('concluida', true)->count();
        $percentual  = $total > 0 ? round($concluidas / $total * 100) : 0;

        return response()->json(['message' => 'Progresso registrado.', 'progresso' => [
            'percentual' => $percentual,
            'concluido'  => $percentual >= 100,
        ]]);
    }

    // POST /franquia/td/ead/{cursoId}/provas/{provaId}/responder
    public function responderProva(Request $request, int $cursoId, int $provaId)
    {
        $franquiaId = $this->tokenContextId($request);
        $userId     = $request->user()?->id;

        $request->validate([
            'respostas' => 'required|array',
        ]);

        $curso = EadCurso::with('aulas:id,curso_id')->where('active', true)->findOrFail($cursoId);
        $prova = \App\Models\EadProva::where('curso_id', $cursoId)->with('questoes')->findOrFail($provaId);

        $totalAulas = $curso->aulas->count();
        $concluidas = EadProgresso::where('franquia_id', $franquiaId)
            ->whereIn('aula_id', $curso->aulas->pluck('id'))
            ->where('concluida', true)->count();

        if ($totalAulas > 0 && $concluidas < $totalAulas) {
            return response()->json(['message' => 'Complete todas as aulas primeiro.'], 403);
        }

        $questoes = $prova->questoes;
        $totalQuestoes = $questoes->count();
        if ($totalQuestoes === 0) {
            return response()->json(['message' => 'Esta prova não possui questões cadastradas.'], 422);
        }

        $respostasUsuario = $request->input('respostas');
        $acertos = 0;

        foreach ($questoes as $q) {
            $respUser = $respostasUsuario[$q->id] ?? null;
            if ($respUser !== null && strtolower(trim($respUser)) === strtolower(trim($q->resposta_correta))) {
                $acertos++;
            }
        }

        $nota = (int) round(($acertos / $totalQuestoes) * 100);
        $aprovado = $nota >= $prova->nota_minima;

        $resultado = \App\Models\EadProvaResposta::create([
            'franquia_id' => $franquiaId,
            'user_id'     => $userId,
            'prova_id'    => $prova->id,
            'respostas'   => $respostasUsuario,
            'nota'        => $nota,
            'aprovado'    => $aprovado,
        ]);

        $certificadoEmitido = false;
        if ($aprovado) {
            $hasCert = \App\Models\EadCertificado::where('franquia_id', $franquiaId)
                ->where('curso_id', $cursoId)
                ->exists();
            if (!$hasCert) {
                \App\Models\EadCertificado::create([
                    'franquia_id' => $franquiaId,
                    'user_id'     => $userId,
                    'curso_id'    => $cursoId,
                ]);
                $certificadoEmitido = true;
            }
        }

        return response()->json([
            'data' => [
                'nota'                => $nota,
                'aprovado'            => $aprovado,
                'certificado_emitido' => $certificadoEmitido,
            ]
        ]);
    }
}
