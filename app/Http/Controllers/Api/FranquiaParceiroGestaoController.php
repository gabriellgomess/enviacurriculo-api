<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parceiro;
use Illuminate\Http\Request;

class FranquiaParceiroGestaoController extends Controller
{
    // GET /franquia/parceiros
    public function index(Request $request)
    {
        $parceiros = Parceiro::where('active', true)
            ->orderBy('razao_social')
            ->paginate(20);

        return response()->json([
            'data' => $parceiros->getCollection()->map(fn($p) => [
                'id'           => $p->id,
                'razao_social' => $p->razao_social,
                'categoria'    => $p->categoria,
                'cidade'       => $p->cidade,
                'estado'       => $p->estado,
                'email'        => $p->email,
                'telefone'     => $p->telefone,
                'active'       => $p->active,
            ]),
            'meta' => [
                'total'        => $parceiros->total(),
                'per_page'     => $parceiros->perPage(),
                'current_page' => $parceiros->currentPage(),
                'last_page'    => $parceiros->lastPage(),
            ],
        ]);
    }

    // GET /franquia/parceiros/{id}
    public function show(int $id)
    {
        $parceiro = Parceiro::with('servicos')->where('active', true)->findOrFail($id);

        return response()->json(['data' => [
            'id'           => $parceiro->id,
            'razao_social' => $parceiro->razao_social,
            'categoria'    => $parceiro->categoria,
            'descricao'    => $parceiro->descricao,
            'cidade'       => $parceiro->cidade,
            'estado'       => $parceiro->estado,
            'email'        => $parceiro->email,
            'telefone'     => $parceiro->telefone,
            'site'         => null,
            'active'       => $parceiro->active,
        ]]);
    }
}
