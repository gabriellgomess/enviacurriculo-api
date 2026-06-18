<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaCurriculo;
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

        // Currículos recebidos por canal — fonte é o Banco de Currículos da empresa
        $recebidos = EmpresaCurriculo::where('empresa_id', $empresaId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN origem = 'franquia'   THEN 1 ELSE 0 END) as franquia,
                SUM(CASE WHEN origem = 'plataforma' THEN 1 ELSE 0 END) as plataforma,
                SUM(CASE WHEN origem IN ('manual','copia_base') THEN 1 ELSE 0 END) as empresa
            ")
            ->first();

        // Funil de conversão continua baseado nos envios (candidaturas em vagas)
        $totalEnvios = Envio::whereIn('vaga_id', $vagaIds)->count();

        return response()->json([
            'data' => [
                'recebidos' => [
                    'total'      => (int) $recebidos->total,
                    'franquia'   => (int) $recebidos->franquia,
                    'plataforma' => (int) $recebidos->plataforma,
                    'empresa'    => (int) $recebidos->empresa,
                ],
                'conversao' => [
                    'recebidos'      => $totalEnvios,
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
