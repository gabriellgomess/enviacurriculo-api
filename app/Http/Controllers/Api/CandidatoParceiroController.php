<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parceiro;
use App\Models\ParceiroVisualizacao;
use App\Models\Candidato;
use Illuminate\Http\Request;

class CandidatoParceiroController extends Controller
{
    // GET /candidato/parceiros
    public function index(Request $request)
    {
        $query = Parceiro::where('active', true)
            ->with(['servicos.categoria:id,nome']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('razao_social', 'like', "%{$s}%")
                  ->orWhere('nome_empresa', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%")
                  ->orWhere('descricao', 'like', "%{$s}%");
            });
        }

        if ($request->filled('estado'))    $query->where('estado', $request->estado);
        if ($request->filled('cidade'))    $query->where('cidade', 'like', "%{$request->cidade}%");
        if ($request->filled('categoria')) {
            $query->whereHas('servicos.categoria', function ($q) use ($request) {
                $q->where('nome', $request->categoria);
            });
        }

        $parceiros = $query->orderBy('razao_social')->paginate(30);

        return response()->json([
            'data' => $parceiros->items(),
            'meta' => $parceiros->toArray(),
        ]);
    }

    // GET /candidato/parceiros/{id}
    public function show($id)
    {
        $parceiro = Parceiro::where('active', true)
            ->with(['servicos.categoria:id,nome'])
            ->findOrFail($id);

        return response()->json(['data' => $parceiro]);
    }

    // POST /candidato/parceiros/{id}/visualizar
    public function visualizar(Request $request, $id)
    {
        $parceiro = Parceiro::where('active', true)->findOrFail($id);

        $request->validate(['tipo' => 'nullable|in:visualizacao,telefone,email,proposta']);

        $user = $request->user();
        $candidato = Candidato::where('user_id', $user->id)->first();

        $usuarioNome = $user->name;
        $email = $user->email;
        $telefone = $candidato?->telefone ?? $user->phone;

        ParceiroVisualizacao::create([
            'parceiro_id'  => $parceiro->id,
            'empresa_id'   => null,
            'empresa_nome' => null,
            'usuario_nome' => $usuarioNome,
            'telefone'     => $telefone,
            'email'        => $email,
            'tipo'         => $request->tipo ?? 'visualizacao',
        ]);

        return response()->json(['message' => 'Visualização registrada.']);
    }
}
