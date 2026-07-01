<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KanbanTipo;
use Illuminate\Http\Request;

class AdminTipoKanbanController extends Controller
{
    // GET /api/admin/configuracoes/tipo-kanban
    public function index()
    {
        $tipos = KanbanTipo::orderByDesc('created_at')->get();
        return response()->json(['data' => $tipos]);
    }

    // POST /api/admin/configuracoes/tipo-kanban
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'descricao' => 'nullable|string',
        ]);

        $tipo = KanbanTipo::create($validated);

        return response()->json(['message' => 'Tipo Kanban criado com sucesso.', 'data' => $tipo], 201);
    }

    // PUT /api/admin/configuracoes/tipo-kanban/{id}
    public function update(Request $request, int $id)
    {
        $tipo = KanbanTipo::findOrFail($id);

        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'descricao' => 'nullable|string',
        ]);

        $tipo->update($validated);

        return response()->json(['message' => 'Tipo Kanban atualizado.', 'data' => $tipo]);
    }

    // DELETE /api/admin/configuracoes/tipo-kanban/{id}
    public function destroy(int $id)
    {
        $tipo = KanbanTipo::findOrFail($id);
        $tipo->delete();

        return response()->json(['message' => 'Tipo Kanban excluído.']);
    }
}
