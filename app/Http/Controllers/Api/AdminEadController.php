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
            'modulo'          => 'nullable|string|max:100',
            'video_url'       => 'nullable|string|max:255',
            'video'           => 'nullable|file|max:102400|mimes:mp4,mov,avi,webm',
            'duracao_minutos' => 'required|integer|min:1',
        ]);

        $videoUrl = $validated['video_url'] ?? null;

        if ($request->hasFile('video')) {
            $path = $request->file('video')->store('ead/videos', 'public');
            $videoUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        $nextOrdem = $curso->aulas()->max('ordem') + 1;

        $aula = EadAula::create([
            'curso_id'        => $curso->id,
            'modulo'          => $validated['modulo'] ?? null,
            'titulo'          => $validated['titulo'],
            'video_url'       => $videoUrl,
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
            'modulo'          => 'nullable|string|max:100',
            'video_url'       => 'nullable|string|max:255',
            'video'           => 'nullable|file|max:102400|mimes:mp4,mov,avi,webm',
            'duracao_minutos' => 'required|integer|min:1',
            'ordem'           => 'required|integer',
        ]);

        $videoUrl = $validated['video_url'] ?? $aula->video_url;

        if ($request->hasFile('video')) {
            if ($aula->video_url && str_contains($aula->video_url, 'storage/ead/videos')) {
                $oldPath = str_replace(url('storage/'), '', $aula->video_url);
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('video')->store('ead/videos', 'public');
            $videoUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        $aula->update([
            'modulo'          => $validated['modulo'] ?? $aula->modulo,
            'titulo'          => $validated['titulo'],
            'video_url'       => $videoUrl,
            'duracao_minutos' => $validated['duracao_minutos'],
            'ordem'           => $validated['ordem'],
        ]);

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
