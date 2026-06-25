<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaColaborador;
use App\Models\EmpresaCurriculo;
use App\Models\EmpresaEntrevista;
use App\Models\Envio;
use App\Models\TesteAgendado;
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
                'conversao' => $this->funilConversao($empresaId, $vagaIds, $totalEnvios),
                'vagas_ativas'        => Vaga::where('empresa_id', $empresaId)
                                            ->where('status', 'publicada')
                                            ->count(),
                'entrevistas_semana'  => EmpresaEntrevista::where('empresa_id', $empresaId)
                                            ->whereBetween('data', [now()->startOfWeek(), now()->endOfWeek()])
                                            ->count(),
                'aniversariantes_mes' => EmpresaColaborador::where('empresa_id', $empresaId)
                                            ->whereNotNull('data_nascimento')
                                            ->whereMonth('data_nascimento', now()->month)
                                            ->count(),
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
            'data' => $this->funilConversao($empresaId, $vagaIds, $total),
        ]);
    }

    /**
     * Funil de conversao da empresa, a partir de envios (status_empresa),
     * entrevistas e testes praticos agendados.
     */
    private function funilConversao(int $empresaId, \Illuminate\Support\Collection $vagaIds, int $recebidos): array
    {
        $porStatus = Envio::whereIn('vaga_id', $vagaIds)
            ->selectRaw('status_empresa, COUNT(*) as total')
            ->groupBy('status_empresa')
            ->pluck('total', 'status_empresa');

        return [
            'recebidos'     => $recebidos,
            'entrevistados' => EmpresaEntrevista::where('empresa_id', $empresaId)->distinct('candidato_id')->count('candidato_id'),
            'teste_pratico' => TesteAgendado::where('empresa_id', $empresaId)->distinct('candidato_id')->count('candidato_id'),
            'aprovados'     => (int) ($porStatus['aprovado'] ?? 0),
            'reprovados'    => (int) ($porStatus['reprovado'] ?? 0),
            'desistentes'   => (int) ($porStatus['desistiu'] ?? 0),
        ];
    }
}
