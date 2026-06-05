<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\FranquiaAgendaEvento;
use Illuminate\Http\Request;

class FranquiaAgendaController extends Controller
{
    use HasTokenContext;

    // GET /franquia/agenda?mes=&ano=
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $query = FranquiaAgendaEvento::where('franquia_id', $franquiaId)
            ->orderBy('data_inicio');

        if ($request->filled('mes') && $request->filled('ano')) {
            $query->whereMonth('data_inicio', $request->mes)
                  ->whereYear('data_inicio', $request->ano);
        }

        return response()->json(['data' => $query->get()]);
    }

    // POST /franquia/agenda
    public function store(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $validated = $request->validate([
            'titulo'       => 'required|string|max:255',
            'descricao'    => 'nullable|string',
            'data_inicio'  => 'required|date',
            'data_fim'     => 'nullable|date|after_or_equal:data_inicio',
            'tipo'         => 'nullable|in:reuniao,entrevista,visita,treinamento,outro',
            'local'        => 'nullable|string|max:255',
            'candidato_id' => 'nullable|integer|exists:candidatos,id',
            'empresa_id'   => 'nullable|integer|exists:empresas,id',
            'vaga_id'      => 'nullable|integer|exists:vagas,id',
        ]);

        $evento = FranquiaAgendaEvento::create(array_merge($validated, ['franquia_id' => $franquiaId]));

        return response()->json(['message' => 'Evento criado.', 'data' => ['id' => $evento->id]], 201);
    }

    // PUT /franquia/agenda/{id}
    public function update(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $evento = FranquiaAgendaEvento::where('franquia_id', $franquiaId)->findOrFail($id);

        $validated = $request->validate([
            'titulo'       => 'sometimes|required|string|max:255',
            'descricao'    => 'nullable|string',
            'data_inicio'  => 'sometimes|required|date',
            'data_fim'     => 'nullable|date',
            'tipo'         => 'nullable|in:reuniao,entrevista,visita,treinamento,outro',
            'local'        => 'nullable|string|max:255',
            'candidato_id' => 'nullable|integer|exists:candidatos,id',
            'empresa_id'   => 'nullable|integer|exists:empresas,id',
            'vaga_id'      => 'nullable|integer|exists:vagas,id',
        ]);

        $evento->update($validated);

        return response()->json(['message' => 'Evento atualizado.']);
    }

    // DELETE /franquia/agenda/{id}
    public function destroy(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        FranquiaAgendaEvento::where('franquia_id', $franquiaId)->findOrFail($id)->delete();
        return response()->json(['message' => 'Evento removido.']);
    }
}
