<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComissaoTipo;
use App\Models\FinanceiroConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFinanceiroController extends Controller
{
    /* ─── Configurações por tipo de franquia ─────────────────────────── */

    public function indexConfigs(string $categoria)
    {
        $this->validateCategoria($categoria);

        return response()->json(
            FinanceiroConfig::where('categoria', $categoria)
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function storeConfig(Request $request, string $categoria)
    {
        $this->validateCategoria($categoria);

        $data = $request->validate([
            'tipo_franquia' => 'required|in:premium,start,s_start',
            'valor'         => 'required|numeric|min:0',
        ]);

        $config = FinanceiroConfig::create([
            ...$data,
            'categoria'  => $categoria,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($config, 201);
    }

    public function updateConfig(Request $request, string $categoria, FinanceiroConfig $config)
    {
        $this->validateCategoria($categoria);

        if ($config->categoria !== $categoria) {
            return response()->json(['message' => 'Registro não pertence a esta categoria.'], 403);
        }

        $data = $request->validate([
            'tipo_franquia' => 'required|in:premium,start,s_start',
            'valor'         => 'required|numeric|min:0',
        ]);

        $config->update($data);

        return response()->json($config);
    }

    public function destroyConfig(string $categoria, FinanceiroConfig $config)
    {
        $this->validateCategoria($categoria);

        if ($config->categoria !== $categoria) {
            return response()->json(['message' => 'Registro não pertence a esta categoria.'], 403);
        }

        $config->delete();

        return response()->json(['message' => 'Registro excluído com sucesso.']);
    }

    private function validateCategoria(string $categoria): void
    {
        abort_unless(
            in_array($categoria, FinanceiroConfig::CATEGORIAS, true),
            404,
            'Categoria inválida.'
        );
    }

    /* ─── Tipos de comissão (por indicação) ──────────────────────────── */

    public function indexComissaoTipos()
    {
        return response()->json(ComissaoTipo::orderBy('tipo')->get());
    }

    public function updateComissaoTipo(Request $request, ComissaoTipo $comissaoTipo)
    {
        $data = $request->validate([
            'percentual' => 'required|numeric|min:0|max:100',
        ]);

        $comissaoTipo->update($data);

        return response()->json($comissaoTipo);
    }

    /* ─── Relatório de faturamento (todas as franquias) ──────────────── */

    public function faturamentos(Request $request)
    {
        $query = DB::table('franquia_contas_receber as cr')
            ->join('franquias as f', 'f.id', '=', 'cr.franquia_id')
            ->select([
                'cr.id',
                'cr.empresa_nome',
                'cr.candidato_nome',
                'cr.vaga_nome',
                DB::raw('COALESCE(cr.franchise_nome, f.nome) as franchise_nome'),
                'cr.salario',
                'cr.taxa_servico',
                'cr.valor_bruto',
                'cr.valor_liquido',
                'cr.status',
                'cr.data_faturamento',
            ])
            ->whereNotNull('cr.data_faturamento');

        if ($request->filled('empresa')) {
            $query->where('cr.empresa_nome', 'like', '%' . $request->empresa . '%');
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('cr.status', $request->status);
        }

        if ($request->filled('mes')) { // formato YYYY-MM
            $query->where('cr.data_faturamento', 'like', $request->mes . '%');
        }

        if ($request->filled('franquia_id')) {
            $query->where('cr.franquia_id', $request->franquia_id);
        }

        $items = $query->orderByDesc('cr.data_faturamento')->get();

        return response()->json([
            'data'   => $items,
            'totais' => [
                'bruto'   => round($items->sum('valor_bruto'), 2),
                'liquido' => round($items->sum('valor_liquido'), 2),
            ],
        ]);
    }
}
