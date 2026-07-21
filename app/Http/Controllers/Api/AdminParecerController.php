<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CandidatoParecer;
use App\Models\Franquia;
use Illuminate\Http\Request;

class AdminParecerController extends Controller
{
    // GET /api/admin/pareceres
    public function index(Request $request)
    {
        $query = CandidatoParecer::with([
            'candidato.user:id,name',
            'vaga.empresa:id,nome_empresa,nome_fantasia,razao_social',
            'empresa:id,nome_empresa,nome_fantasia,razao_social',
            'franquia:id,nome,codigo,responsavel',
            'criador:id,name'
        ])->orderByDesc('id');

        if ($request->filled('busca')) {
            $term = '%' . $request->busca . '%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('candidato.user', function ($sq) use ($term) {
                    $sq->where('name', 'like', $term);
                })
                ->orWhereHas('candidato', function ($sq) use ($term) {
                    $sq->where('nome', 'like', $term);
                })
                ->orWhereHas('franquia', function ($sq) use ($term) {
                    $sq->where('nome', 'like', $term)->orWhere('codigo', 'like', $term)->orWhere('responsavel', 'like', $term);
                })
                ->orWhereHas('empresa', function ($sq) use ($term) {
                    $sq->where('nome_empresa', 'like', $term)->orWhere('nome_fantasia', 'like', $term)->orWhere('razao_social', 'like', $term);
                })
                ->orWhereHas('vaga.empresa', function ($sq) use ($term) {
                    $sq->where('nome_empresa', 'like', $term)->orWhere('nome_fantasia', 'like', $term)->orWhere('razao_social', 'like', $term);
                })
                ->orWhereHas('criador', function ($sq) use ($term) {
                    $sq->where('name', 'like', $term);
                });
            });
        }

        $perPage = min((int) $request->get('per_page', 50), 200);
        $pareceres = $query->paginate($perPage);

        $items = collect($pareceres->items())->map(function ($p) {
            $franquia = $p->franquia;

            if (!$franquia && $p->candidato?->franquia_id) {
                $franquia = Franquia::find($p->candidato->franquia_id);
            }
            if (!$franquia && $p->criado_por) {
                $ctx = \DB::table('user_contexts')->where('user_id', $p->criado_por)->where('role', 'franquia')->first();
                if ($ctx) {
                    $franquia = Franquia::find($ctx->context_id);
                }
            }

            $empresaNome = $p->empresa?->nome_fantasia
                ?? $p->empresa?->razao_social
                ?? $p->empresa?->nome_empresa
                ?? $p->vaga?->empresa?->nome_fantasia
                ?? $p->vaga?->empresa?->razao_social
                ?? $p->vaga?->empresa?->nome_empresa
                ?? null;

            return [
                'id'               => $p->id,
                'status_aprovacao' => $p->status_aprovacao ?? 'pendente',
                'franquia'         => $franquia ? [
                    'id'          => $franquia->id,
                    'codigo'      => $franquia->codigo,
                    'nome'        => $franquia->nome,
                    'responsavel' => $franquia->responsavel,
                ] : null,
                'empresa_nome'     => $empresaNome,
                'candidato_nome'   => $p->candidato?->user?->name ?? $p->candidato?->nome ?? '—',
                'consultor_nome'   => $p->criador?->name ?? 'Sistema',
                'vaga_titulo'      => $p->vaga?->titulo ?? 'Geral',
                'texto'            => $p->texto,
                'nota'             => $p->nota,
                'dados'            => $p->dados,
                'created_at'       => $p->created_at,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $pareceres->total(),
                'per_page'     => $pareceres->perPage(),
                'current_page' => $pareceres->currentPage(),
                'last_page'    => $pareceres->lastPage(),
            ]
        ]);
    }

    // DELETE /api/admin/pareceres/{id}
    public function destroy(int $id)
    {
        $parecer = CandidatoParecer::findOrFail($id);
        $parecer->delete();

        return response()->json(['message' => 'Parecer excluído com sucesso.']);
    }
}
