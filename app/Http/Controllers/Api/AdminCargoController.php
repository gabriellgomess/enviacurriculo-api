<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use Illuminate\Http\Request;

class AdminCargoController extends Controller
{
    // GET /api/admin/configuracoes/cargos (também exposta em /api/cargos para outros painéis)
    public function index()
    {
        $cargos = Cargo::orderBy('nome')->get();
        return response()->json(['data' => $cargos]);
    }

    // POST /api/admin/configuracoes/cargos
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
        ]);

        $cargo = Cargo::create(['nome' => $validated['nome']]);

        return response()->json(['message' => 'Cargo criado.', 'data' => $cargo], 201);
    }

    // PUT /api/admin/configuracoes/cargos/{id}
    public function update(Request $request, int $id)
    {
        $cargo = Cargo::findOrFail($id);

        $validated = $request->validate([
            'nome' => 'required|string|max:255',
        ]);

        $cargo->update(['nome' => $validated['nome']]);

        return response()->json(['message' => 'Cargo atualizado com sucesso.', 'data' => $cargo]);
    }

    // DELETE /api/admin/configuracoes/cargos/{id}
    public function destroy(int $id)
    {
        $cargo = Cargo::findOrFail($id);
        $cargo->delete();

        return response()->json(['message' => 'Cargo excluído.']);
    }
}
