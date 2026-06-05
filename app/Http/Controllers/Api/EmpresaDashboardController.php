<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Envio;
use App\Models\Vaga;
use Illuminate\Http\Request;

class EmpresaDashboardController extends Controller
{
    use HasTokenContext;

    // GET /empresa/dashboard
    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        // Vagas da empresa (ids para usar nos envios)
        $vagaIds = Vaga::where('empresa_id', $empresaId)->pluck('id');

        // Envios (currículos recebidos) por canal — fonte ainda não foi implementada
        // por enquanto retornamos só o total real
        $totalRecebidos = Envio::whereIn('vaga_id', $vagaIds)->count();

        return response()->json([
            'data' => [
                'recebidos' => [
                    'total'      => $totalRecebidos,
                    'franquia'   => 0,
                    'plataforma' => $totalRecebidos,
                    'empresa'    => 0,
                ],
                'conversao' => [
                    'recebidos'      => $totalRecebidos,
                    'entrevistados'  => 0,
                    'teste_pratico'  => 0,
                    'aprovados'      => 0,
                    'reprovados'     => 0,
                    'desistentes'    => 0,
                ],
                'vagas_ativas'        => Vaga::where('empresa_id', $empresaId)
                                            ->where('status', 'publicada')
                                            ->count(),
                'entrevistas_semana'  => 0, // tabela entrevistas ainda não existe
                'aniversariantes_mes' => 0, // tabela colaboradores ainda não existe
                'plano' => [
                    'chave'     => $empresa->plano,
                    'nome'      => 'Plano ' . ucfirst($empresa->plano ?? 'padrao'),
                    'expira_em' => null,
                ],
            ],
        ]);
    }

    // GET /empresa/dashboard/conversao?canal={canal}
    public function conversao(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $vagaIds = Vaga::where('empresa_id', $empresaId)->pluck('id');
        $total = Envio::whereIn('vaga_id', $vagaIds)->count();

        return response()->json([
            'data' => [
                'recebidos'     => $total,
                'entrevistados' => 0,
                'teste_pratico' => 0,
                'aprovados'     => 0,
                'reprovados'    => 0,
                'desistentes'   => 0,
            ],
        ]);
    }
}
