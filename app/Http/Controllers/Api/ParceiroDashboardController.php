<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\HasTokenContext;
use App\Models\ParceiroVisualizacao;
use App\Models\ParceiroAgendamento;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ParceiroDashboardController extends Controller
{
    use HasTokenContext;

    // GET /parceiro/dashboard
    public function index(Request $request)
    {
        $parceiroId = $this->tokenContextId($request);

        $stats = [
            'total'    => ParceiroVisualizacao::where('parceiro_id', $parceiroId)->count(),
            'telefone' => ParceiroVisualizacao::where('parceiro_id', $parceiroId)->where('tipo', 'telefone')->count(),
            'email'    => ParceiroVisualizacao::where('parceiro_id', $parceiroId)->where('tipo', 'email')->count(),
            'proposta' => ParceiroVisualizacao::where('parceiro_id', $parceiroId)->where('tipo', 'proposta')->count(),
        ];

        $visualizacoes = ParceiroVisualizacao::where('parceiro_id', $parceiroId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($v) => [
                'id'         => $v->id,
                'empresa'    => $v->empresa_nome,
                'usuario'    => $v->usuario_nome,
                'telefone'   => $v->telefone,
                'email'      => $v->email,
                'tipo'       => $v->tipo,
                'created_at' => $v->created_at,
            ]);

        $receita_ultimos_meses = $this->receitaUltimosMeses($parceiroId);

        return response()->json([
            'data' => compact('stats', 'visualizacoes', 'receita_ultimos_meses'),
        ]);
    }

    private function receitaUltimosMeses(int $parceiroId): array
    {
        $meses = collect(range(4, 0))->map(function ($ago) use ($parceiroId) {
            $mes  = Carbon::now()->subMonths($ago);
            $count = ParceiroAgendamento::where('parceiro_id', $parceiroId)
                ->where('status', 'concluido')
                ->whereYear('data', $mes->year)
                ->whereMonth('data', $mes->month)
                ->count();

            return ['mes' => $mes->locale('pt_BR')->isoFormat('MMM'), 'valor' => $count];
        });

        return $meses->values()->all();
    }
}
