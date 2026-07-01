<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EadCurso;
use App\Models\EadAula;
use Illuminate\Http\Request;

class AdminEadController extends Controller
{
    // GET /api/admin/ead/cursos
    public function indexCursos()
    {
        $cursos = EadCurso::withCount('aulas')->orderBy('titulo')->get();
        return response()->json(['data' => $cursos]);
    }

    // POST /api/admin/ead/cursos
    public function storeCurso(Request $request)
    {
        $validated = $request->validate([
            'titulo'    => 'required|string|max:255',
            'descricao' => 'nullable|string',
        ]);

        $curso = EadCurso::create($validated + ['active' => true]);

        return response()->json(['message' => 'Curso criado com sucesso.', 'data' => $curso], 201);
    }

    // PUT /api/admin/ead/cursos/{id}
    public function updateCurso(Request $request, int $id)
    {
        $curso = EadCurso::findOrFail($id);

        $validated = $request->validate([
            'titulo'    => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'active'    => 'required|boolean',
        ]);

        $curso->update($validated);

        return response()->json(['message' => 'Curso atualizado com sucesso.', 'data' => $curso]);
    }

    // DELETE /api/admin/ead/cursos/{id}
    public function destroyCurso(int $id)
    {
        $curso = EadCurso::findOrFail($id);
        $curso->aulas()->delete();
        $curso->delete();

        return response()->json(['message' => 'Curso excluído com sucesso.']);
    }

    // GET /api/admin/ead/cursos/{id}/aulas
    public function indexAulas(int $id)
    {
        $curso = EadCurso::findOrFail($id);
        $aulas = $curso->aulas()->orderBy('ordem')->get();
        return response()->json(['data' => $aulas]);
    }

    // POST /api/admin/ead/cursos/{id}/aulas
    public function storeAula(Request $request, int $id)
    {
        $curso = EadCurso::findOrFail($id);

        $validated = $request->validate([
            'titulo'          => 'required|string|max:255',
            'duracao_minutos' => 'required|integer|min:1',
        ]);

        $nextOrdem = $curso->aulas()->max('ordem') + 1;

        $aula = EadAula::create([
            'curso_id'        => $curso->id,
            'titulo'          => $validated['titulo'],
            'duracao_minutos' => $validated['duracao_minutos'],
            'ordem'           => $nextOrdem,
        ]);

        return response()->json(['message' => 'Aula cadastrada com sucesso.', 'data' => $aula], 201);
    }

    // PUT /api/admin/ead/aulas/{id}
    public function updateAula(Request $request, int $id)
    {
        $aula = EadAula::findOrFail($id);

        $validated = $request->validate([
            'titulo'          => 'required|string|max:255',
            'duracao_minutos' => 'required|integer|min:1',
            'ordem'           => 'required|integer',
        ]);

        $aula->update($validated);

        return response()->json(['message' => 'Aula atualizada com sucesso.', 'data' => $aula]);
    }

    // DELETE /api/admin/ead/aulas/{id}
    public function destroyAula(int $id)
    {
        $aula = EadAula::findOrFail($id);
        $aula->delete();

        return response()->json(['message' => 'Aula excluída com sucesso.']);
    }
}
