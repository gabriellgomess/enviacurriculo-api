<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TipoFormacao;
use Illuminate\Http\Request;

class AdminTipoFormacaoController extends Controller
{
    // GET /api/admin/configuracoes/tipos-formacao (também exposta em /api/tipos-formacao para outros painéis)
    public function index()
    {
        $tipos = TipoFormacao::orderBy('nome')->get();
        return response()->json(['data' => $tipos]);
    }

    // POST /api/admin/configuracoes/tipos-formacao
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
        ]);

        $tipo = TipoFormacao::create(['nome' => $validated['nome']]);

        return response()->json(['message' => 'Tipo de formação criado.', 'data' => $tipo], 201);
    }

    // PUT /api/admin/configuracoes/tipos-formacao/{id}
    public function update(Request $request, int $id)
    {
        $tipo = TipoFormacao::findOrFail($id);

        $validated = $request->validate([
            'nome' => 'required|string|max:255',
        ]);

        $tipo->update(['nome' => $validated['nome']]);

        return response()->json(['message' => 'Tipo de formação atualizado com sucesso.', 'data' => $tipo]);
    }

    // DELETE /api/admin/configuracoes/tipos-formacao/{id}
    public function destroy(int $id)
    {
        $tipo = TipoFormacao::findOrFail($id);
        $tipo->delete();

        return response()->json(['message' => 'Tipo de formação excluído.']);
    }
}
