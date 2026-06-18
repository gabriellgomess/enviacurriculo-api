<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\HasTokenContext;
use App\Models\ParceiroTarefa;
use Illuminate\Http\Request;

class ParceiroTarefaController extends Controller
{
    use HasTokenContext;

    // GET /parceiro/tarefas
    public function index(Request $request)
    {
        $parceiroId = $this->tokenContextId($request);

        $tarefas = ParceiroTarefa::where('parceiro_id', $parceiroId)
            ->orderBy('data_tarefa')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $tarefas]);
    }

    // POST /parceiro/tarefas
    public function store(Request $request)
    {
        $parceiroId = $this->tokenContextId($request);

        $data = $request->validate([
            'titulo'      => 'required|string|max:255',
            'descricao'   => 'nullable|string|max:2000',
            'data_tarefa' => 'required|date',
            'hora'        => 'nullable|date_format:H:i',
        ]);

        $tarefa = ParceiroTarefa::create([
            'parceiro_id' => $parceiroId,
            'titulo'      => $data['titulo'],
            'descricao'   => $data['descricao'] ?? null,
            'data_tarefa' => $data['data_tarefa'],
            'hora'        => $data['hora'] ?? null,
            'concluida'   => false,
        ]);

        return response()->json($tarefa, 201);
    }

    // PATCH /parceiro/tarefas/{id}/toggle
    public function toggle(Request $request, $id)
    {
        $parceiroId = $this->tokenContextId($request);

        $tarefa = ParceiroTarefa::where('parceiro_id', $parceiroId)->findOrFail($id);
        $tarefa->update(['concluida' => ! $tarefa->concluida]);

        return response()->json($tarefa);
    }

    // DELETE /parceiro/tarefas/{id}
    public function destroy(Request $request, $id)
    {
        $parceiroId = $this->tokenContextId($request);

        $tarefa = ParceiroTarefa::where('parceiro_id', $parceiroId)->findOrFail($id);
        $tarefa->delete();

        return response()->noContent();
    }
}
