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

    // DELETE /api/admin/configuracoes/beneficios/{id}
    public function destroy(int $id)
    {
        $beneficio = BeneficioCatalogo::findOrFail($id);
        $beneficio->delete();

        return response()->json(['message' => 'Benefício excluído do catálogo.']);
    }
}
