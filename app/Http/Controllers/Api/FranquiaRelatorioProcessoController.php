<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Envio;
use App\Models\Franquia;
use App\Models\Vaga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Relatório de Processos da franquia — mesma tela do admin, com escopo.
 *
 * O que cada plano enxerga (definido com o cliente em 21/08/2026):
 *
 *   start   → apenas os envios que a própria franquia registrou.
 *   premium → o mesmo, mais tudo que acontece nas empresas e nas vagas que ela
 *             cuida, inclusive candidatos encaminhados por outras franquias.
 *
 * O premium é superconjunto do start de propósito: escopar só por empresa/vaga
 * esconderia dela os candidatos que ela mesma mandou para vagas da Matriz, e
 * ela veria menos que uma start nesse aspecto.
 *
 * Exportação (PDF/Excel) é exclusiva do premium, e a regra vale aqui no
 * servidor — não só escondendo o botão na tela.
 */
class FranquiaRelatorioProcessoController extends Controller
{
    use HasTokenContext;

    /** Acima disto a requisição só pode ser exportação. */
    private const PER_PAGE_TELA = 100;

    // GET /franquia/relatorios/processos
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $isPremium  = Franquia::find($franquiaId)?->tipo === 'premium';

        $perPage = min((int) $request->input('per_page', 50), 5000);

        // Bloqueio real da exportação: sem isto, a franquia start conseguiria
        // baixar o relatório inteiro chamando a API com per_page alto.
        if ($perPage > self::PER_PAGE_TELA && !$isPremium) {
            return response()->json([
                'message' => 'A exportação do relatório está disponível apenas para franquias Premium.',
            ], 403);
        }

        $query = Envio::with([
            'candidato:id,user_id,cidade,estado',
            'candidato.user:id,name',
            'franquia:id,nome,codigo',
            'vaga:id,titulo,codigo,empresa_id',
            'vaga.empresa:id,razao_social,nome_fantasia',
        ]);

        $this->aplicarEscopo($query, $franquiaId, $isPremium);

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
                // A tela usa isto para decidir se mostra os botões de exportar.
                'pode_exportar'=> $isPremium,
                'tipo'         => $isPremium ? 'premium' : 'start',
            ],
        ]);
    }

    /** Restringe o relatório ao que a franquia tem direito de ver. */
    private function aplicarEscopo($query, int $franquiaId, bool $isPremium): void
    {
        if (!$isPremium) {
            $query->where('franquia_id', $franquiaId);
            return;
        }

        $empresaIds = Empresa::where('franquia_id', $franquiaId)->pluck('id');
        $vagaIds    = Vaga::where('franquia_id', $franquiaId)->pluck('id');

        $query->where(function ($q) use ($franquiaId, $empresaIds, $vagaIds) {
            $q->where('franquia_id', $franquiaId)
              ->orWhereIn('vaga_id', $vagaIds)
              ->orWhereHas('vaga', fn($v) => $v->whereIn('empresa_id', $empresaIds));
        });
    }

    /**
     * Ordenação por colunas relacionadas via subconsulta correlacionada, para
     * não duplicar linhas nem criar ambiguidade de coluna. Mesma lógica do
     * relatório do admin.
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

        $colunaDireta = [
            'vinculado_em'  => 'created_at',
            'data_admissao' => 'data_admissao',
            'salario'       => 'salario_aprovado',
            'status'        => 'status',
        ];

        if ($campo && isset($subconsultas[$campo])) {
            $query->orderBy($subconsultas[$campo](), $dir);
        } elseif ($campo && isset($colunaDireta[$campo])) {
            $query->orderBy($colunaDireta[$campo], $dir);
        } else {
            $query->orderBy('created_at', $dir);
        }

        $query->orderBy('id', $dir);
    }
}
