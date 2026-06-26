<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Franquia;
use App\Models\FranquiaChamado;
use App\Models\MetaFranquia;
use App\Models\Vaga;
use Illuminate\Http\Request;

class FranquiaDashboardController extends Controller
{
    use HasTokenContext;

    // GET /franquia/dashboard
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $franquia   = Franquia::findOrFail($franquiaId);

        // Empresas vinculadas a esta franquia
        $empresaIds = Empresa::where('franquia_id', $franquiaId)->pluck('id');

        // Vagas das empresas da franquia
        $vagaIds = Vaga::whereIn('empresa_id', $empresaIds)->pluck('id');

        $candidatosNovos = Candidato::with('user:id,name')
            ->whereHas('envios', fn($q) => $q->whereIn('vaga_id', $vagaIds))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'user_id', 'cidade', 'estado', 'created_at'])
            ->map(fn($c) => [
                'id'         => $c->id,
                'nome'       => $c->user?->name,
                'cidade'     => $c->cidade,
                'estado'     => $c->estado,
                'created_at' => $c->created_at,
            ]);

        $empresasNovas = Empresa::where('franquia_id', $franquiaId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'razao_social', 'cnpj', 'status', 'created_at']);

        $vagasNovas = Vaga::with('empresa:id,razao_social,nome_fantasia')
            ->whereIn('empresa_id', $empresaIds)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'empresa_id', 'titulo', 'status', 'created_at'])
            ->map(fn($v) => [
                'id'         => $v->id,
                'titulo'     => $v->titulo,
                'empresa'    => $v->empresa?->nome_fantasia ?? $v->empresa?->razao_social,
                'status'     => $v->status,
                'created_at' => $v->created_at,
            ]);

        $vagasSemCandidatos = Vaga::with('empresa:id,razao_social,nome_fantasia')
            ->whereIn('empresa_id', $empresaIds)
            ->where('status', 'publicada')
            ->whereDoesntHave('envios')
            ->limit(5)
            ->get(['id', 'empresa_id', 'titulo', 'created_at'])
            ->map(fn($v) => [
                'id'         => $v->id,
                'titulo'     => $v->titulo,
                'empresa'    => $v->empresa?->nome_fantasia ?? $v->empresa?->razao_social,
                'created_at' => $v->created_at,
            ]);

        return response()->json([
            'data' => [
                'franquia' => [
                    'id'     => $franquia->id,
                    'codigo' => $franquia->codigo,
                    'nome'   => $franquia->nome,
                    'tipo'   => $franquia->tipo,
                ],
                'kpis' => [
                    'metas_ativas'        => MetaFranquia::where('franquia_id', $franquiaId)
                                                ->where('status', 'ativa')->count(),
                    'chamados_abertos'    => FranquiaChamado::where('franquia_id', $franquiaId)
                                                ->whereIn('status', ['aberto', 'em_atendimento'])
                                                ->count(),
                    'total_recebido'      => 0, // financeiro pendente
                    'total_a_receber'     => 0, // financeiro pendente
                    'candidatos_ativos'   => Candidato::whereHas('envios', fn($q) => $q->whereIn('vaga_id', $vagaIds))
                                                ->where('active', true)
                                                ->count(),
                    'vagas_abertas'       => Vaga::whereIn('empresa_id', $empresaIds)
                                                ->where('status', 'publicada')
                                                ->count(),
                    'empresas_vinculadas' => $empresaIds->count(),
                ],
                'candidatos_novos'     => $candidatosNovos,
                'empresas_novas'       => $empresasNovas,
                'vagas_novas'          => $vagasNovas,
                'vagas_sem_candidatos' => $vagasSemCandidatos,
            ],
        ]);
    }
}
