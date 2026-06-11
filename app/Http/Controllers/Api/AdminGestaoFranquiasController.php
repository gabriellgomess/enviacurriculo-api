<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\FranquiaOnboardingItem;
use App\Models\FranquiaOnboardingProgresso;
use App\Models\MetaFranquia;
use App\Models\TipoMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminGestaoFranquiasController extends Controller
{
    /* ─── Tipos de metas ─────────────────────────────────────────────── */

    public function indexTiposMetas()
    {
        return response()->json(TipoMeta::orderBy('nome')->get());
    }

    public function storeTipoMeta(Request $request)
    {
        $data = $this->validateTipoMeta($request);

        return response()->json(TipoMeta::create($data), 201);
    }

    public function updateTipoMeta(Request $request, TipoMeta $tipoMeta)
    {
        $tipoMeta->update($this->validateTipoMeta($request));

        return response()->json($tipoMeta);
    }

    public function destroyTipoMeta(TipoMeta $tipoMeta)
    {
        if ($tipoMeta->metas()->exists()) {
            return response()->json(['message' => 'Há metas vinculadas a este tipo. Exclua-as primeiro.'], 422);
        }

        $tipoMeta->delete();

        return response()->json(['message' => 'Tipo de meta excluído.']);
    }

    private function validateTipoMeta(Request $request): array
    {
        return $request->validate([
            'nome'      => 'required|string|max:255',
            'descricao' => 'nullable|string|max:255',
            'unidade'   => 'required|in:moeda,quantidade',
        ]);
    }

    /* ─── Metas por franquia ─────────────────────────────────────────── */

    public function indexMetas(Request $request)
    {
        $metas = MetaFranquia::with(['franquia:id,nome,codigo', 'tipoMeta:id,nome,unidade'])
            ->when($request->filled('franquia_id'), fn($q) => $q->where('franquia_id', $request->franquia_id))
            ->when($request->filled('status') && $request->status !== 'todos', fn($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->get();

        // Valor atual automático (faturamento bruto da franquia) para metas em moeda
        $faturadoPorFranquia = DB::table('franquia_contas_receber')
            ->select('franquia_id', DB::raw('COALESCE(SUM(valor_bruto),0) as total'))
            ->groupBy('franquia_id')
            ->pluck('total', 'franquia_id');

        $metas->transform(function ($meta) use ($faturadoPorFranquia) {
            $isMoeda = !$meta->tipoMeta || $meta->tipoMeta->unidade === 'moeda';
            if ($isMoeda) {
                $meta->valor_atual = (float) ($faturadoPorFranquia[$meta->franquia_id] ?? 0);
            }
            return $meta;
        });

        return response()->json($metas);
    }

    public function storeMeta(Request $request)
    {
        $data = $this->validateMeta($request);

        $meta = MetaFranquia::create($data);

        return response()->json($meta->load(['franquia:id,nome,codigo', 'tipoMeta:id,nome,unidade']), 201);
    }

    public function updateMeta(Request $request, MetaFranquia $meta)
    {
        $meta->update($this->validateMeta($request));

        return response()->json($meta->load(['franquia:id,nome,codigo', 'tipoMeta:id,nome,unidade']));
    }

    public function destroyMeta(MetaFranquia $meta)
    {
        $meta->delete();

        return response()->json(['message' => 'Meta excluída.']);
    }

    private function validateMeta(Request $request): array
    {
        return $request->validate([
            'franquia_id'  => 'required|integer|exists:franquias,id',
            'tipo_meta_id' => 'nullable|integer|exists:tipos_metas,id',
            'titulo'       => 'required|string|max:255',
            'descricao'    => 'nullable|string',
            'valor_meta'   => 'required|numeric|min:0',
            'valor_atual'  => 'nullable|numeric|min:0',
            'data_inicio'  => 'nullable|date',
            'data_fim'     => 'nullable|date|after_or_equal:data_inicio',
            'status'       => 'required|in:ativa,pausada,concluida,cancelada',
        ]);
    }

    /* ─── Onboarding — itens (etapas que o admin cria) ───────────────── */

    public function indexOnboardingItens()
    {
        return response()->json(FranquiaOnboardingItem::orderBy('ordem')->get());
    }

    public function storeOnboardingItem(Request $request)
    {
        $data = $this->validateOnboardingItem($request);

        return response()->json(FranquiaOnboardingItem::create($data), 201);
    }

    public function updateOnboardingItem(Request $request, FranquiaOnboardingItem $item)
    {
        $item->update($this->validateOnboardingItem($request));

        return response()->json($item);
    }

    public function destroyOnboardingItem(FranquiaOnboardingItem $item)
    {
        $item->delete(); // progresso cai via cascade

        return response()->json(['message' => 'Item de onboarding excluído.']);
    }

    private function validateOnboardingItem(Request $request): array
    {
        return $request->validate([
            'titulo'    => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'ordem'     => 'nullable|integer|min:0',
            'active'    => 'nullable|boolean',
        ]);
    }

    /* ─── Onboarding — acompanhamento por franquia ───────────────────── */

    public function onboardingProgresso(Request $request)
    {
        $totalItens = FranquiaOnboardingItem::where('active', true)->count();

        $franquias = DB::table('franquias as f')
            ->leftJoin('franquia_onboarding_progresso as p', function ($join) {
                $join->on('p.franquia_id', '=', 'f.id')->where('p.concluido', true);
            })
            ->leftJoin('franquia_onboarding_itens as i', function ($join) {
                $join->on('i.id', '=', 'p.item_id')->where('i.active', true);
            })
            ->where('f.active', true)
            ->groupBy('f.id', 'f.nome', 'f.codigo', 'f.tipo')
            ->select('f.id', 'f.nome', 'f.codigo', 'f.tipo',
                DB::raw('COUNT(i.id) as itens_concluidos'))
            ->orderBy('f.nome')
            ->get()
            ->map(fn($f) => [
                'id'               => $f->id,
                'nome'             => $f->nome,
                'codigo'           => $f->codigo,
                'tipo'             => $f->tipo,
                'itens_concluidos' => (int) $f->itens_concluidos,
                'total_itens'      => $totalItens,
                'percentual'       => $totalItens > 0 ? round($f->itens_concluidos / $totalItens * 100) : 0,
            ]);

        return response()->json(['data' => $franquias, 'total_itens' => $totalItens]);
    }

    public function onboardingProgressoFranquia(int $franquiaId)
    {
        $itens = FranquiaOnboardingItem::where('active', true)->orderBy('ordem')->get();

        $progresso = FranquiaOnboardingProgresso::where('franquia_id', $franquiaId)
            ->get()->keyBy('item_id');

        return response()->json($itens->map(fn($item) => [
            'id'           => $item->id,
            'titulo'       => $item->titulo,
            'descricao'    => $item->descricao,
            'ordem'        => $item->ordem,
            'concluido'    => (bool) ($progresso[$item->id]->concluido ?? false),
            'concluido_em' => $progresso[$item->id]->concluido_em ?? null,
        ]));
    }

    /* ─── Vínculos (envios candidato→vaga por franquia) ──────────────── */

    public function vinculos(Request $request)
    {
        $mes = $request->query('mes', now()->format('Y-m')); // YYYY-MM

        $rows = DB::table('envios as e')
            ->join('vagas as v', 'v.id', '=', 'e.vaga_id')
            ->join('franquias as f', 'f.id', '=', 'v.franquia_id')
            ->whereNotNull('v.franquia_id')
            ->where(DB::raw("DATE_FORMAT(e.created_at, '%Y-%m')"), $mes)
            ->groupBy('f.id', 'f.nome', 'f.codigo', DB::raw('DATE(e.created_at)'))
            ->select('f.id as franquia_id', 'f.nome', 'f.codigo',
                DB::raw('DATE(e.created_at) as data'),
                DB::raw('COUNT(*) as total'))
            ->orderBy('f.nome')
            ->get();

        return response()->json(['mes' => $mes, 'data' => $rows]);
    }

    /* ─── Registro de acessos ────────────────────────────────────────── */

    public function acessos(Request $request)
    {
        $logs = AccessLog::query()
            ->when($request->filled('user_type') && $request->user_type !== 'all',
                fn($q) => $q->where('user_type', $request->user_type))
            ->when($request->filled('busca'), fn($q) => $q->where(function ($sub) use ($request) {
                $sub->where('user_name', 'like', "%{$request->busca}%")
                    ->orWhere('user_email', 'like', "%{$request->busca}%");
            }))
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();

        return response()->json($logs);
    }
}
