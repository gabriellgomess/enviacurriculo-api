<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\Request;

class CandidatoEmpresaController extends Controller
{
    // GET /candidato/empresas
    public function index(Request $request)
    {
        $query = Empresa::where('status', 'aprovado')
            ->where('active', true)
            ->withCount(['vagas as vagas_ativas_count' => function ($q) {
                $q->where('status', 'publicada');
            }])
            ->with(['vagas' => function ($q) {
                $q->where('status', 'publicada')->select('id', 'empresa_id', 'titulo');
            }]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('razao_social', 'like', "%{$s}%")
                  ->orWhere('nome_fantasia', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%");
            });
        }

        if ($request->filled('estado')) $query->where('estado', $request->estado);
        if ($request->filled('cidade')) $query->where('cidade', 'like', "%{$request->cidade}%");

        $perPage = min((int) $request->input('per_page', 20), 200);
        $empresas = $query->orderBy('razao_social')->paginate($perPage);

        return response()->json([
            'data' => $empresas->items(),
            'meta' => $empresas->toArray(),
        ]);
    }

    // GET /candidato/empresas/{id}
    public function show($id)
    {
        $empresa = Empresa::where('status', 'aprovado')
            ->where('active', true)
            ->with(['vagas' => function ($q) {
                $q->where('status', 'publicada');
            }, 'beneficios'])
            ->findOrFail($id);

        return response()->json(['data' => $empresa]);
    }
}
