<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NivelVaga;
use Illuminate\Http\Request;

class AdminNivelVagaController extends Controller
{
    // GET /api/admin/configuracoes/tipo-niveis-vagas
    public function index()
    {
        $niveis = NivelVaga::orderBy('ordem')->get();
        return response()->json(['data' => $niveis]);
    }

    // POST /api/admin/configuracoes/tipo-niveis-vagas
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome'  => 'required|string|max:255',
        ]);

        $nextOrdem = NivelVaga::max('ordem') + 1;

        $nivel = NivelVaga::create([
            'nome'  => $validated['nome'],
            'ordem' => $nextOrdem,
        ]);

        return response()->json(['message' => 'Nível de vaga criado.', 'data' => $nivel], 201);
    }

    // PUT /api/admin/configuracoes/tipo-niveis-vagas/{id}
    public function update(Request $request, int $id)
    {
        $nivel = NivelVaga::findOrFail($id);

        $validated = $request->validate([
            'nome' => 'required|string|max:255',
        ]);

        $nivel->update(['nome' => $validated['nome']]);

        return response()->json(['message' => 'Nível de vaga atualizado com sucesso.', 'data' => $nivel]);
    }

    // DELETE /api/admin/configuracoes/tipo-niveis-vagas/{id}
    public function destroy(int $id)
    {
        $nivel = NivelVaga::findOrFail($id);
        $nivel->delete();

        return response()->json(['message' => 'Nível de vaga excluído.']);
    }
}
