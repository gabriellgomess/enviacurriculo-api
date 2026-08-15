<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Envio;
use Illuminate\Http\Request;

/**
 * Relatório de Processos — a lista de candidatos vinculados a vagas, com quem
 * encaminhou, situação e datas.
 *
 * Difere do "Vínculos" de Indicadores, que é uma contagem por franquia e dia.
 * Aqui cada linha é um encaminhamento.
 *
 * A franquia responsável vem de `envios.franquia_id` — quem de fato encaminhou
 * —, e não da dona da vaga. Derivar da vaga colocaria tudo na Unidade Matriz,
 * já que é dela todo o acervo migrado.
 */
class AdminRelatorioProcessoController extends Controller
{
    // GET /admin/relatorios/processos
    public function index(Request $request)
    {
        $query = Envio::with([
            'candidato:id,user_id,cidade,estado',
            'candidato.user:id,name',
            'franquia:id,nome,codigo',
            'vaga:id,titulo,codigo,empresa_id',
            'vaga.empresa:id,razao_social,nome_fantasia',
        ]);

        if ($request->filled('franquia_id')) {
            // 'sem' = encaminhamentos da operação central, sem franquia
            $request->franquia_id === 'sem'
                ? $query->whereNull('franquia_id')
                : $query->where('franquia_id', $request->franquia_id);
        }

        if ($request->filled('empresa_id')) {
            $query->whereHas('vaga', fn($v) => $v->where('empresa_id', $request->empresa_id));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('vinculo_de')) {
            $query->whereDate('created_at', '>=', $request->vinculo_de);
        }
        if ($request->filled('vinculo_ate')) {
            $query->whereDate('created_at', '<=', $request->vinculo_ate);
        }

        if ($request->filled('admissao_de')) {
            $query->whereDate('data_admissao', '>=', $request->admissao_de);
        }
        if ($request->filled('admissao_ate')) {
            $query->whereDate('data_admissao', '<=', $request->admissao_ate);
        }

        if ($request->filled('busca')) {
            $termo = trim($request->busca);
            $query->where(function ($q) use ($termo) {
                $q->whereHas('candidato.user', fn($u) => $u->where('name', 'like', "%{$termo}%"))
                  ->orWhereHas('vaga', fn($v) => $v->where('titulo', 'like', "%{$termo}%")
                                                   ->orWhere('codigo', 'like', "%{$termo}%"));
            });
        }

        // Teto alto porque a exportação em PDF pede o relatório inteiro.
        $perPage = min((int) $request->input('per_page', 50), 5000);

        $envios = $query->orderByDesc('created_at')->paginate($perPage);

        $itens = $envios->getCollection()->map(fn($e) => [
            'id'              => $e->id,
            'franquia'        => $e->franquia?->nome ?? 'Administração',
            'candidato'       => $e->candidato?->user?->name ?? '—',
            'candidato_local' => trim(implode('/', array_filter([$e->candidato?->cidade, $e->candidato?->estado]))) ?: null,
            'vaga'            => $e->vaga?->titulo ?? '—',
            'vaga_codigo'     => $e->vaga?->codigo,
            'empresa'         => $e->vaga?->empresa?->razao_social ?? $e->vaga?->empresa?->nome_fantasia ?? '—',
            'status'          => $e->status,
            'vinculado_em'    => $e->created_at?->toDateString(),
            'data_admissao'   => $e->data_admissao?->toDateString(),
        ]);

        // Contagem por situação sobre o resultado filtrado inteiro, não sobre a
        // página — é o número que o cabeçalho do PDF precisa mostrar.
        $porStatus = (clone $query)->reorder()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => $itens,
            'meta' => [
                'total'        => $envios->total(),
                'per_page'     => $envios->perPage(),
                'current_page' => $envios->currentPage(),
                'last_page'    => $envios->lastPage(),
                'por_status'   => $porStatus,
            ],
        ]);
    }
}
