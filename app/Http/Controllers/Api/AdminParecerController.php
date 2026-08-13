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
            'candidato:id,user_id,rua,numero,bairro,cidade,estado',
            'candidato.user:id,name',
            'vaga.empresa:id,nome_fantasia,razao_social',
            'empresa:id,nome_fantasia,razao_social',
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
                    $sq->where('nome_fantasia', 'like', $term)->orWhere('razao_social', 'like', $term);
                })
                ->orWhereHas('vaga.empresa', function ($sq) use ($term) {
                    $sq->where('nome_fantasia', 'like', $term)->orWhere('razao_social', 'like', $term);
                })
                ->orWhereHas('criador', function ($sq) use ($term) {
                    $sq->where('name', 'like', $term);
                });
            });
        }

        if ($request->filled('franquia_id')) {
            $query->where('franquia_id', $request->franquia_id);
        }

        // A empresa pode estar no próprio parecer ou vir pela vaga — a tela
        // mostra as duas origens, então o filtro precisa cobrir as duas.
        if ($request->filled('empresa_id')) {
            $empresaId = $request->empresa_id;
            $query->where(function ($q) use ($empresaId) {
                $q->where('empresa_id', $empresaId)
                  ->orWhereHas('vaga', fn($v) => $v->where('empresa_id', $empresaId));
            });
        }

        if ($request->filled('status')) {
            $status = $request->status;
            // Parecer antigo tem status_aprovacao nulo e é exibido como pendente.
            $query->when($status === 'pendente',
                fn($q) => $q->where(fn($s) => $s->where('status_aprovacao', 'pendente')
                                                ->orWhereNull('status_aprovacao')),
                fn($q) => $q->where('status_aprovacao', $status),
            );
        }

        // Período do parecer
        if ($request->filled('data_de')) {
            $query->whereDate('candidato_pareceres.created_at', '>=', $request->data_de);
        }
        if ($request->filled('data_ate')) {
            $query->whereDate('candidato_pareceres.created_at', '<=', $request->data_ate);
        }

        // Períodos de vinculação e admissão vivem no envio, não no parecer.
        // O par candidato+vaga é o que liga um ao outro.
        $filtrosEnvio = ['vinculo_de', 'vinculo_ate', 'admissao_de', 'admissao_ate'];

        if (collect($filtrosEnvio)->contains(fn($c) => $request->filled($c))) {
            $query->whereExists(function ($q) use ($request) {
                $q->selectRaw('1')
                  ->from('envios')
                  ->whereColumn('envios.candidato_id', 'candidato_pareceres.candidato_id')
                  ->whereColumn('envios.vaga_id', 'candidato_pareceres.vaga_id');

                if ($request->filled('vinculo_de'))   $q->whereDate('envios.created_at', '>=', $request->vinculo_de);
                if ($request->filled('vinculo_ate'))  $q->whereDate('envios.created_at', '<=', $request->vinculo_ate);
                if ($request->filled('admissao_de'))  $q->whereDate('envios.data_admissao', '>=', $request->admissao_de);
                if ($request->filled('admissao_ate')) $q->whereDate('envios.data_admissao', '<=', $request->admissao_ate);
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
                ?? $p->vaga?->empresa?->nome_fantasia
                ?? $p->vaga?->empresa?->razao_social
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
                'candidato'        => $p->candidato ? [
                    'id'              => $p->candidato_id,
                    'nome'            => $p->candidato->user?->name ?? $p->candidato->nome,
                    'cpf'             => $p->candidato->cpf,
                    'telefone'        => $p->candidato->telefone,
                    'email'           => $p->candidato->email ?? $p->candidato->user?->email,
                    'data_nascimento' => $p->candidato->data_nascimento?->toDateString(),
                    'escolaridade'    => $p->candidato->escolaridade,
                    'cidade'          => $p->candidato->cidade,
                    'estado'          => $p->candidato->estado,
                ] : null,
                'candidato_nome'   => $p->candidato?->user?->name ?? $p->candidato?->nome ?? '—',
                'candidato_endereco' => $p->candidato ? implode(', ', array_filter([
                    $p->candidato->logradouro,
                    $p->candidato->numero,
                    $p->candidato->bairro,
                    $p->candidato->cidade,
                    $p->candidato->estado,
                ])) : null,
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
