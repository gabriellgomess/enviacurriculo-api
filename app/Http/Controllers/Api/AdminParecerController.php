<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CandidatoParecer;
use Illuminate\Http\Request;

class AdminParecerController extends Controller
{
    // GET /api/admin/pareceres
    public function index(Request $request)
    {
        $query = CandidatoParecer::with([
            'candidato:id,user_id',
            'candidato.user:id,name',
            'vaga:id,titulo,empresa_id',
            'vaga.empresa:id,nome_empresa',
            'franquia:id,nome,codigo',
            'criador:id,name'
        ])->orderByDesc('created_at');

        if ($request->filled('busca')) {
            $term = '%' . $request->busca . '%';
            $query->whereHas('candidato.user', function ($q) use ($term) {
                $q->where('name', 'like', $term);
            })->orWhereHas('franquia', function ($q) use ($term) {
                $q->where('nome', 'like', $term)->orWhere('codigo', 'like', $term);
            });
        }

        $pareceres = $query->paginate(20);

        return response()->json([
            'data' => $pareceres->items(),
            'meta' => [
                'total'        => $pareceres->total(),
                'per_page'     => $pareceres->perPage(),
                'current_page' => $pareceres->currentPage(),
                'last_page'    => $pareceres->lastPage(),
            ]
        ]);
    }

    // DELETE /api/admin/pareceres/{id}
    public function destroy(int $id)
    {
        $parecer = CandidatoParecer::findOrFail($id);
        $parecer->delete();

        return response()->json(['message' => 'Parecer excluído com sucesso.']);
    }
}
