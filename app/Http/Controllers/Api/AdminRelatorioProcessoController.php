<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Envio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            // "Pendente" na interface é `enviado` no banco. O `pendente` entra
            // junto como rede de segurança: se algum registro tiver escapado do
            // ec:normalizar-status-envios, ainda aparece no lugar certo.
            $request->status === 'enviado'
                ? $query->whereIn('status', ['enviado', 'pendente'])
                : $query->where('status', $request->status);
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

        if ($request->filled('salario_de')) {
            $query->where('salario_aprovado', '>=', $request->salario_de);
        }
        if ($request->filled('salario_ate')) {
            $query->where('salario_aprovado', '<=', $request->salario_ate);
        }

        if ($request->filled('busca')) {
            $termo = trim($request->busca);
            $query->where(function ($q) use ($termo) {
                $q->whereHas('candidato.user', fn($u) => $u->where('name', 'like', "%{$termo}%"))
                  ->orWhereHas('vaga', fn($v) => $v->where('titulo', 'like', "%{$termo}%")
                                                   ->orWhere('codigo', 'like', "%{$termo}%"));
            });
        }

        $this->aplicarOrdenacao($query, $request->get('sort'), $request->get('dir'));

        // Teto alto porque a exportação em PDF/Excel pede o relatório inteiro.
        $perPage = min((int) $request->input('per_page', 50), 5000);

        $envios = $query->paginate($perPage);

        $itens = $envios->getCollection()->map(fn($e) => [
            'id'              => $e->id,
            'franquia'        => $e->franquia?->nome ?? 'Administração',
            'candidato'       => $e->candidato?->user?->name ?? '—',
            'candidato_local' => trim(implode('/', array_filter([$e->candidato?->cidade, $e->candidato?->estado]))) ?: null,
            'vaga'            => $e->vaga?->titulo ?? '—',
            'vaga_codigo'     => $e->vaga?->codigo,
            'empresa'         => $e->vaga?->empresa?->razao_social ?? $e->vaga?->empresa?->nome_fantasia ?? '—',
            'status'          => $e->status,
            'salario'         => $e->salario_aprovado,
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

    /**
     * Ordena por qualquer coluna, inclusive as de tabelas relacionadas, sem
     * alterar o SELECT/WHERE principal da consulta — cada coluna relacionada
     * usa uma subconsulta correlacionada (ORDER BY (SELECT ...)), em vez de
     * LEFT JOIN, para não arriscar ambiguidade de coluna nem duplicar linhas.
     */
    private function aplicarOrdenacao($query, ?string $campo, ?string $direcao): void
    {
        $dir = $direcao === 'asc' ? 'asc' : 'desc';

        $subconsultas = [
            'franquia' => fn() => DB::table('franquias')
                ->select('nome')
                ->whereColumn('franquias.id', 'envios.franquia_id'),
            'candidato' => fn() => DB::table('candidatos')
                ->join('users', 'users.id', '=', 'candidatos.user_id')
                ->select('users.name')
                ->whereColumn('candidatos.id', 'envios.candidato_id'),
            'vaga' => fn() => DB::table('vagas')
                ->select('titulo')
                ->whereColumn('vagas.id', 'envios.vaga_id'),
            'empresa' => fn() => DB::table('vagas')
                ->join('empresas', 'empresas.id', '=', 'vagas.empresa_id')
                ->select('empresas.razao_social')
                ->whereColumn('vagas.id', 'envios.vaga_id'),
        ];

        $colunasDiretas = ['status', 'vinculado_em', 'data_admissao', 'salario'];
        $colunaDireta = [
            'vinculado_em'  => 'created_at',
            'data_admissao' => 'data_admissao',
            'salario'       => 'salario_aprovado',
            'status'        => 'status',
        ];

        if ($campo && isset($subconsultas[$campo])) {
            $query->orderBy($subconsultas[$campo](), $dir);
        } elseif ($campo && in_array($campo, $colunasDiretas, true)) {
            $query->orderBy($colunaDireta[$campo], $dir);
        } else {
            $query->orderBy('created_at', $dir);
        }

        // Critério de desempate estável quando a coluna ordenada tem repetidos.
        $query->orderBy('id', $dir);
    }
}
