<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Envio;
use Illuminate\Http\Request;

class CandidatoEnvioController extends Controller
{
    private function candidatoDoUsuario(): Candidato
    {
        return Candidato::where('user_id', auth()->id())->firstOrFail();
    }

    // GET /candidato/envios
    public function index(Request $request)
    {
        $c = $this->candidatoDoUsuario();

        $envios = Envio::where('candidato_id', $c->id)
            ->with([
                'vaga:id,titulo,cidade,estado,empresa_id',
                'vaga.empresa:id,razao_social,nome_fantasia',
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $envios->items(),
            'meta' => $envios->toArray(),
        ]);
    }

    // GET /candidato/envios/{id}
    public function show($id)
    {
        $c = $this->candidatoDoUsuario();
        $envio = Envio::where('candidato_id', $c->id)
            ->with(['vaga.empresa', 'curriculo'])
            ->findOrFail($id);

        return response()->json(['data' => $envio]);
    }
}
