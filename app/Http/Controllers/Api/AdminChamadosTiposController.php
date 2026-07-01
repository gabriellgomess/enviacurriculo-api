<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChamadosTipo;
use Illuminate\Http\Request;

class AdminChamadosTiposController extends Controller
{
    // GET /api/admin/chamados/tipos
    public function index()
    {
        $tipos = ChamadosTipo::orderByDesc('created_at')->get();
        return response()->json(['data' => $tipos]);
    }

    // POST /api/admin/chamados/tipos
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'descricao' => 'nullable|string',
        ]);

        $tipo = ChamadosTipo::create($validated);

        return response()->json(['message' => 'Tipo de chamado criado com sucesso.', 'data' => $tipo], 201);
    }

    // DELETE /api/admin/chamados/tipos/{id}
    public function destroy(int $id)
    {
        $tipo = ChamadosTipo::findOrFail($id);
        $tipo->delete();

        return response()->json(['message' => 'Tipo de chamado excluído.']);
    }
}
