<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscConvite;
use App\Models\DiscLeadResultado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Teste DISC público acessado por token (tela /disc-teste/:token do candidate).
 * Contrato documentado em CHANGES.md (Ignacio).
 */
class DiscPublicoController extends Controller
{
    /**
     * GET /disc-teste/{token}
     */
    public function show(string $token)
    {
        $convite = DiscConvite::with(['lead:id,nome_completo', 'candidato.user:id,name'])
            ->where('token', $token)->first();

        if (!$convite) {
            return response()->json(['status' => 'invalid', 'message' => 'Convite não encontrado.'], 404);
        }

        if ($convite->status === 'respondido') {
            return response()->json(['status' => 'respondido']);
        }

        if ($convite->isExpired()) {
            return response()->json(['status' => 'expired', 'message' => 'Convite expirado.'], 410);
        }

        $questoes = DB::table('disc_questoes')
            ->orderBy('grupo')
            ->get(['grupo', 'opcao_a', 'opcao_b', 'opcao_c', 'opcao_d',
                   'fator_a', 'fator_b', 'fator_c', 'fator_d']);

        return response()->json([
            'status'     => 'pendente',
            'convite_id' => $convite->id,
            'lead_id'    => $convite->lead_id,
            'lead_nome'  => $convite->lead?->nome_completo
                ?? $convite->candidato?->user?->name,
            'questoes'   => $questoes,
        ]);
    }

    /**
     * POST /disc-teste/{token}/responder
     */
    public function responder(Request $request, string $token)
    {
        $convite = DiscConvite::where('token', $token)->first();

        if (!$convite) {
            return response()->json(['status' => 'invalid', 'message' => 'Convite não encontrado.'], 404);
        }

        if ($convite->status === 'respondido') {
            return response()->json(['status' => 'respondido', 'message' => 'Este teste já foi respondido.'], 422);
        }

        if ($convite->isExpired()) {
            return response()->json(['status' => 'expired', 'message' => 'Convite expirado.'], 410);
        }

        $data = $request->validate([
            'score_d'          => 'required|integer|min:0|max:100',
            'score_i'          => 'required|integer|min:0|max:100',
            'score_s'          => 'required|integer|min:0|max:100',
            'score_c'          => 'required|integer|min:0|max:100',
            'perfil_dominante' => 'required|in:D,I,S,C',
            'respostas'        => 'nullable|array',
        ]);

        return DB::transaction(function () use ($convite, $data) {
            if ($convite->candidato_id) {
                // Convite enviado por empresa a um candidato
                \App\Models\CandidatoDisc::create([
                    'candidato_id'     => $convite->candidato_id,
                    'aplicado_por'     => $convite->criado_por,
                    'convite_id'       => $convite->id,
                    'score_d'          => $data['score_d'],
                    'score_i'          => $data['score_i'],
                    'score_s'          => $data['score_s'],
                    'score_c'          => $data['score_c'],
                    'perfil_dominante' => $data['perfil_dominante'],
                    'respostas'        => $data['respostas'] ?? null,
                ]);
            } else {
                // Convite enviado pelo admin a um lead (Seja Franqueado)
                DiscLeadResultado::create([
                    'convite_id'       => $convite->id,
                    'lead_id'          => $convite->lead_id,
                    'score_d'          => $data['score_d'],
                    'score_i'          => $data['score_i'],
                    'score_s'          => $data['score_s'],
                    'score_c'          => $data['score_c'],
                    'perfil_dominante' => $data['perfil_dominante'],
                    'respostas'        => $data['respostas'] ?? null,
                ]);
            }

            $convite->update(['status' => 'respondido']);

            return response()->json(['message' => 'Teste respondido com sucesso. Obrigado!'], 201);
        });
    }
}
