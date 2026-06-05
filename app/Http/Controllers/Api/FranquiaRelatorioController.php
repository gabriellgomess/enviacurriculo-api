<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Envio;
use App\Models\Vaga;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FranquiaRelatorioController extends Controller
{
    use HasTokenContext;

    // GET /franquia/relatorios?periodo=mes|trimestre|semestre|ano&data_inicio=&data_fim=
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        [$inicio, $fim] = $this->resolvePeriodo($request);

        $empresaIds = Empresa::where('franquia_id', $franquiaId)->pluck('id');
        $vagaIds    = Vaga::whereIn('empresa_id', $empresaIds)->pluck('id');

        // Candidatos
        $totalCandidatos = Candidato::whereHas('envios', fn($q) => $q->whereIn('vaga_id', $vagaIds))
            ->where('active', true)
            ->count();

        $novosCandidatos = Candidato::whereHas('envios', fn($q) => $q->whereIn('vaga_id', $vagaIds))
            ->whereBetween('created_at', [$inicio, $fim])
            ->count();

        $aprovados   = Envio::whereIn('vaga_id', $vagaIds)->where('status', 'aprovado')
            ->whereBetween('updated_at', [$inicio, $fim])->count();
        $emProcesso  = Envio::whereIn('vaga_id', $vagaIds)->where('status', 'em_processo')->count();

        // Vagas
        $totalAbertas  = Vaga::whereIn('empresa_id', $empresaIds)->where('status', 'publicada')->count();
        $totalFechadas = Vaga::whereIn('empresa_id', $empresaIds)->where('status', 'encerrada')
            ->whereBetween('data_fechamento', [$inicio, $fim])->count();

        $mediaCandidatos = $totalAbertas > 0
            ? round(Envio::whereIn('vaga_id', $vagaIds)->count() / max($totalAbertas, 1), 1)
            : 0;

        // Empresas
        $empresasAtivas = $empresaIds->count();
        $empresasNovas  = Empresa::where('franquia_id', $franquiaId)
            ->whereBetween('created_at', [$inicio, $fim])->count();

        return response()->json(['data' => [
            'periodo'    => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()],
            'candidatos' => [
                'total_cadastrados'  => $totalCandidatos,
                'novos_no_periodo'   => $novosCandidatos,
                'aprovados'          => $aprovados,
                'em_processo'        => $emProcesso,
            ],
            'vagas' => [
                'total_abertas'              => $totalAbertas,
                'total_fechadas'             => $totalFechadas,
                'media_candidatos_por_vaga'  => $mediaCandidatos,
            ],
            'financeiro' => [
                'receita_bruta'       => 0,
                'comissoes_recebidas' => 0,
                'despesas'            => 0,
                'resultado_liquido'   => 0,
            ],
            'empresas' => [
                'ativas'           => $empresasAtivas,
                'novas_no_periodo' => $empresasNovas,
            ],
        ]]);
    }

    private function resolvePeriodo(Request $request): array
    {
        if ($request->filled('data_inicio') && $request->filled('data_fim')) {
            return [Carbon::parse($request->data_inicio)->startOfDay(), Carbon::parse($request->data_fim)->endOfDay()];
        }

        $periodo = $request->query('periodo', 'mes');
        $fim = Carbon::now()->endOfDay();

        $inicio = match ($periodo) {
            'trimestre' => Carbon::now()->subMonths(3)->startOfDay(),
            'semestre'  => Carbon::now()->subMonths(6)->startOfDay(),
            'ano'       => Carbon::now()->subYear()->startOfDay(),
            default     => Carbon::now()->startOfMonth(),
        };

        return [$inicio, $fim];
    }
}
