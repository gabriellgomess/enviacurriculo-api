<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CandidatoDisc;
use App\Models\Candidato;
use Illuminate\Http\Request;

class AdminDiscController extends Controller
{
    // GET /api/admin/disc
    public function index(Request $request)
    {
        $query = CandidatoDisc::with([
            'candidato:id,nome_completo',
            'aplicador:id,name'
        ])->orderByDesc('created_at');

        if ($request->filled('busca')) {
            $term = '%' . $request->busca . '%';
            $query->whereHas('candidato', function ($q) use ($term) {
                $q->where('nome_completo', 'like', $term);
            });
        }

        $resultados = $query->paginate(20);

        return response()->json([
            'data' => $resultados->items(),
            'meta' => [
                'total'        => $resultados->total(),
                'per_page'     => $resultados->perPage(),
                'current_page' => $resultados->currentPage(),
                'last_page'    => $resultados->lastPage(),
            ]
        ]);
    }

    // POST /api/admin/disc
    public function store(Request $request)
    {
        $validated = $request->validate([
            'candidato_id'     => 'required|integer|exists:candidatos,id',
            'perfil_dominante' => 'required|string|max:1',
            'score_d'          => 'required|integer|min:0',
            'score_i'          => 'required|integer|min:0',
            'score_s'          => 'required|integer|min:0',
            'score_c'          => 'required|integer|min:0',
            'respostas'        => 'nullable|array',
        ]);

        $resultado = CandidatoDisc::create($validated + [
            'aplicado_por' => auth()->id()
        ]);

        return response()->json(['message' => 'Resultado DISC registrado.', 'data' => $resultado], 201);
    }
}
