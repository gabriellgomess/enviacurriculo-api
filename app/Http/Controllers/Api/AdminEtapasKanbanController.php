<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KanbanEtapa;
use Illuminate\Http\Request;

class AdminEtapasKanbanController extends Controller
{
    // GET /api/admin/configuracoes/etapas-kanban
    public function index()
    {
        $etapas = KanbanEtapa::whereNull('empresa_id')->orderBy('ordem')->get();
        return response()->json(['data' => $etapas]);
    }

    // POST /api/admin/configuracoes/etapas-kanban
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome'      => 'required|string|max:50',
            'cor'       => 'required|string|max:9',
            'descricao' => 'nullable|string',
        ]);

        $nextOrdem = KanbanEtapa::whereNull('empresa_id')->max('ordem') + 1;

        $etapa = KanbanEtapa::create([
            'empresa_id' => null,
            'nome'       => $validated['nome'],
            'cor'        => $validated['cor'],
            'ordem'      => $nextOrdem,
        ]);

        return response()->json(['message' => 'Etapa cadastrada com sucesso.', 'data' => $etapa], 201);
    }

    // PUT /api/admin/configuracoes/etapas-kanban/{id}
    public function update(Request $request, int $id)
    {
        $etapa = KanbanEtapa::whereNull('empresa_id')->findOrFail($id);

        $validated = $request->validate([
            'nome'      => 'required|string|max:50',
            'cor'       => 'required|string|max:9',
            'descricao' => 'nullable|string',
        ]);

        $etapa->update($validated);

        return response()->json(['message' => 'Etapa atualizada.', 'data' => $etapa]);
    }

    // DELETE /api/admin/configuracoes/etapas-kanban/{id}
    public function destroy(int $id)
    {
        $etapa = KanbanEtapa::whereNull('empresa_id')->findOrFail($id);
        $etapa->delete();

        return response()->json(['message' => 'Etapa excluída.']);
    }

    // PUT /api/admin/configuracoes/etapas-kanban/{id}/reorder
    public function reorder(Request $request, int $id)
    {
        $request->validate([
            'direction' => 'required|in:up,down',
        ]);

        $etapa = KanbanEtapa::whereNull('empresa_id')->findOrFail($id);
        $etapas = KanbanEtapa::whereNull('empresa_id')->orderBy('ordem')->get();

        $index = $etapas->pluck('id')->search($id);
        if ($index === false) {
            return response()->json(['message' => 'Etapa não encontrada.'], 404);
        }

        $direction = $request->direction;
        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapIndex < 0 || $swapIndex >= $etapas->count()) {
            return response()->json(['message' => 'Movimento inválido.'], 422);
        }

        $otherEtapa = $etapas[$swapIndex];

        $tempOrdem = $etapa->ordem;
        $etapa->update(['ordem' => $otherEtapa->ordem]);
        $otherEtapa->update(['ordem' => $tempOrdem]);

        return response()->json(['message' => 'Etapas reordenadas com sucesso.']);
    }
}
