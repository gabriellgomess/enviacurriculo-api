<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BeneficioCatalogo;
use Illuminate\Http\Request;

class AdminBeneficiosController extends Controller
{
    // GET /api/admin/configuracoes/beneficios
    public function index()
    {
        $beneficios = BeneficioCatalogo::orderBy('categoria')->orderBy('nome')->get();
        return response()->json(['data' => $beneficios]);
    }

    // POST /api/admin/configuracoes/beneficios
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'icone'     => 'nullable|string|max:50',
            'categoria' => 'required|string|max:50',
        ]);

        $beneficio = BeneficioCatalogo::create($validated + [
            'is_sistema' => true,
        ]);

        return response()->json(['message' => 'Benefício criado no catálogo do sistema.', 'data' => $beneficio], 201);
    }

    // PUT /api/admin/configuracoes/beneficios/{id}
    public function update(Request $request, int $id)
    {
        $beneficio = BeneficioCatalogo::findOrFail($id);

        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'icone'     => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
        ]);

        $beneficio->update(array_filter([
            'nome'      => $validated['nome'],
            'icone'     => $validated['icone'] ?? null,
            'categoria' => $validated['categoria'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(['message' => 'Benefício atualizado com sucesso.', 'data' => $beneficio]);
    }

    // DELETE /api/admin/configuracoes/beneficios/{id}
    public function destroy(int $id)
    {
        $beneficio = BeneficioCatalogo::findOrFail($id);
        $beneficio->delete();

        return response()->json(['message' => 'Benefício excluído do catálogo.']);
    }
}
