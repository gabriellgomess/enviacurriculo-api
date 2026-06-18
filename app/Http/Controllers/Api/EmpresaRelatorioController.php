<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaColaborador;
use App\Models\EmpresaEntrevista;
use App\Models\Envio;
use App\Models\Vaga;
use App\Support\Planos;
use Illuminate\Http\Request;

class EmpresaRelatorioController extends Controller
{
    use HasTokenContext;

    // GET /empresa/relatorios/recrutamento
    public function recrutamento(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $vagaIds = Vaga::where('empresa_id', $empresaId)->pluck('id');

        $porOrigem = Envio::whereIn('vaga_id', $vagaIds)
            ->selectRaw("COALESCE(NULLIF(origem, ''), 'Não informado') as origem, COUNT(*) as total")
            ->groupBy('origem')
            ->pluck('total', 'origem');

        return response()->json([
            'data' => [
                'total'      => (int) $porOrigem->sum(),
                'por_origem' => $porOrigem,
            ],
        ]);
    }

    // GET /empresa/relatorios/taxa-conversao
    public function taxaConversao(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $vagas = Vaga::where('empresa_id', $empresaId)
            ->withCount('envios as candidatos')
            ->get(['id', 'titulo']);

        $entrevistasPorVaga = EmpresaEntrevista::where('empresa_id', $empresaId)
            ->whereNotNull('vaga_id')
            ->selectRaw('vaga_id, COUNT(*) as total')
            ->groupBy('vaga_id')
            ->pluck('total', 'vaga_id');

        $contratadosPorVaga = Envio::whereIn('vaga_id', $vagas->pluck('id'))
            ->whereNotNull('data_admissao')
            ->selectRaw('vaga_id, COUNT(*) as total')
            ->groupBy('vaga_id')
            ->pluck('total', 'vaga_id');

        $rows = $vagas->map(function ($v) use ($entrevistasPorVaga, $contratadosPorVaga) {
            $candidatos  = (int) $v->candidatos;
            $entrevistas = (int) ($entrevistasPorVaga[$v->id] ?? 0);
            $contratados = (int) ($contratadosPorVaga[$v->id] ?? 0);
            $taxa = $candidatos > 0 ? (int) round($contratados / $candidatos * 100) : 0;

            return [
                'vaga'        => $v->titulo,
                'candidatos'  => $candidatos,
                'entrevistas' => $entrevistas,
                'contratados' => $contratados,
                'taxa'        => $taxa,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    // GET /empresa/plano/utilizacao
    public function planoUtilizacao(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        $vagaIds         = Vaga::where('empresa_id', $empresaId)->pluck('id');
        $vagasCount      = $vagaIds->count();
        $curriculosCount = Envio::whereIn('vaga_id', $vagaIds)->count();
        $colaboradores   = EmpresaColaborador::where('empresa_id', $empresaId)->count();

        $podePublicar = Planos::permitePublicarVagas($empresa->plano);
        $limiteVagas  = $podePublicar ? 'Ilimitado' : '0';

        $rows = [
            [
                'recurso'    => 'Vagas publicadas',
                'limite'     => $limiteVagas,
                'utilizado'  => $vagasCount,
                'disponivel' => $podePublicar ? 'Ilimitado' : max(0, 0 - $vagasCount),
            ],
            [
                'recurso'    => 'Currículos recebidos',
                'limite'     => 'Ilimitado',
                'utilizado'  => $curriculosCount,
                'disponivel' => 'Ilimitado',
            ],
            [
                'recurso'    => 'Colaboradores cadastrados',
                'limite'     => 'Ilimitado',
                'utilizado'  => $colaboradores,
                'disponivel' => 'Ilimitado',
            ],
        ];

        return response()->json(['data' => $rows]);
    }

    // GET /empresa/mensalidades
    // Stub: sem sistema de cobranca/mensalidades (mesma situacao de faturamentos).
    public function mensalidades()
    {
        return response()->json(['data' => []]);
    }
}
