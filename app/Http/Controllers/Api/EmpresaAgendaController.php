<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\EmpresaAgendaTarefa;
use Illuminate\Http\Request;

class EmpresaAgendaController extends Controller
{
    use HasTokenContext;

    // GET /empresa/agenda/tarefas
    public function tarefasIndex(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $tarefas = EmpresaAgendaTarefa::where('empresa_id', $empresaId)
            ->orderBy('data_tarefa')
            ->orderBy('hora')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $tarefas]);
    }

    // POST /empresa/agenda/tarefas
    public function tarefasStore(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate([
            'titulo'      => 'required|string|max:255',
            'descricao'   => 'nullable|string|max:2000',
            'data_tarefa' => 'required|date',
            'hora'        => 'nullable|date_format:H:i',
            'concluida'   => 'nullable|boolean',
        ]);

        $tarefa = EmpresaAgendaTarefa::create(array_merge($data, [
            'empresa_id' => $empresaId,
            'concluida'  => $data['concluida'] ?? false,
        ]));

        return response()->json($tarefa, 201);
    }

    // PATCH /empresa/agenda/tarefas/{id}
    public function tarefasUpdate(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $tarefa    = EmpresaAgendaTarefa::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate([
            'titulo'      => 'sometimes|required|string|max:255',
            'descricao'   => 'nullable|string|max:2000',
            'data_tarefa' => 'sometimes|required|date',
            'hora'        => 'nullable|date_format:H:i',
            'concluida'   => 'sometimes|boolean',
        ]);

        $tarefa->update($data);

        return response()->json($tarefa);
    }

    // DELETE /empresa/agenda/tarefas/{id}
    public function tarefasDestroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $tarefa    = EmpresaAgendaTarefa::where('empresa_id', $empresaId)->findOrFail($id);
        $tarefa->delete();

        return response()->noContent();
    }
}
