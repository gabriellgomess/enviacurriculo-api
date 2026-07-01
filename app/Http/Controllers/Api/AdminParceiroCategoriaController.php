<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParceiroCategoria;
use Illuminate\Http\Request;

class AdminParceiroCategoriaController extends Controller
{
    // GET /api/admin/parceiros/categorias
    public function index()
    {
        $categorias = ParceiroCategoria::orderBy('nome')->get();
        return response()->json(['data' => $categorias]);
    }

    // POST /api/admin/parceiros/categorias
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255|unique:parceiros_categorias,nome',
        ]);

        $nextOrdem = ParceiroCategoria::max('ordem') + 1;

        $categoria = ParceiroCategoria::create([
            'nome'  => $validated['nome'],
            'ordem' => $nextOrdem,
        ]);

        return response()->json(['message' => 'Categoria criada com sucesso.', 'data' => $categoria], 201);
    }

    // DELETE /api/admin/parceiros/categorias/{id}
    public function destroy(int $id)
    {
        $categoria = ParceiroCategoria::findOrFail($id);
        $categoria->delete();

        return response()->json(['message' => 'Categoria excluída com sucesso.']);
    }
}
