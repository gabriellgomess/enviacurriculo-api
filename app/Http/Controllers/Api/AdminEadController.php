<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EadCurso;
use App\Models\EadAula;
use App\Models\EadProva;
use App\Models\EadProvaQuestao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminEadController extends Controller
{
    // GET /api/admin/ead/cursos
    public function indexCursos()
    {
        $cursos = EadCurso::with(['aulas' => function ($q) {
            $q->orderBy('ordem');
        }, 'provas.questoes'])->orderBy('titulo')->get();
        
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
        $curso->provas()->each(function ($p) {
            $p->questoes()->delete();
            $p->delete();
        });
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

    // POST /api/admin/ead/cursos/{cursoId}/provas
    public function storeProva(Request $request, int $cursoId)
    {
        $curso = EadCurso::findOrFail($cursoId);

        $validated = $request->validate([
            'titulo'                    => 'required|string|max:255',
            'nota_minima'               => 'required|integer|min:1|max:100',
            'questoes'                  => 'required|array|min:1',
            'questoes.*.pergunta'        => 'required|string',
            'questoes.*.opcao_a'        => 'required|string',
            'questoes.*.opcao_b'        => 'required|string',
            'questoes.*.opcao_c'        => 'required|string',
            'questoes.*.opcao_d'        => 'required|string',
            'questoes.*.resposta_correta' => 'required|string|in:a,b,c,d',
        ]);

        return DB::transaction(function () use ($validated, $curso) {
            $prova = EadProva::create([
                'curso_id'    => $curso->id,
                'titulo'      => $validated['titulo'],
                'nota_minima' => $validated['nota_minima'],
            ]);

            foreach ($validated['questoes'] as $index => $q) {
                EadProvaQuestao::create([
                    'prova_id'         => $prova->id,
                    'pergunta'         => $q['pergunta'],
                    'opcao_a'          => $q['opcao_a'],
                    'opcao_b'          => $q['opcao_b'],
                    'opcao_c'          => $q['opcao_c'],
                    'opcao_d'          => $q['opcao_d'],
                    'resposta_correta' => $q['resposta_correta'],
                    'ordem'            => $index,
                ]);
            }

            return response()->json([
                'message' => 'Prova criada com sucesso.',
                'data'    => $prova->load('questoes')
            ], 201);
        });
    }

    // DELETE /api/admin/ead/provas/{id}
    public function destroyProva(int $id)
    {
        $prova = EadProva::findOrFail($id);
        $prova->questoes()->delete();
        $prova->delete();

        return response()->json(['message' => 'Prova excluída com sucesso.']);
    }
}
