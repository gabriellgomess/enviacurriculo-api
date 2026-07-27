<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parceiro;
use Illuminate\Http\Request;

class FranquiaParceiroGestaoController extends Controller
{
    // GET /franquia/parceiros
    public function index(Request $request)
    {
        // Parceiros do cadastro público preenchem nome_empresa (razao_social
        // fica nula), então ordenamos e exibimos pelo nome efetivo.
        // A tela filtra por categoria via servicos[].categoria.nome
        $parceiros = Parceiro::with(['servicos:id,parceiro_id,categoria_id,nome_servico,descricao,proposta_url',
                                     'servicos.categoria:id,nome'])
            ->where('active', true)
            ->orderByRaw('COALESCE(NULLIF(nome_empresa, ""), razao_social)')
            ->paginate(20);

        return response()->json([
            'data' => $parceiros->getCollection()->map(fn($p) => [
                'id'           => $p->id,
                'nome_empresa' => $p->nome_empresa ?: $p->razao_social,
                'razao_social' => $p->razao_social,
                'categoria'    => $p->categoria,
                'descricao'    => $p->descricao,
                'logo_url'     => $p->logo_url,
                'bairro'       => $p->bairro,
                'cidade'       => $p->cidade,
                'estado'       => $p->estado,
                'email'        => $p->email,
                'telefone'     => $p->telefone,
                'servicos'     => $p->servicos,
                'active'       => $p->active,
            ]),
            'meta' => [
                'total'        => $parceiros->total(),
                'per_page'     => $parceiros->perPage(),
                'current_page' => $parceiros->currentPage(),
                'last_page'    => $parceiros->lastPage(),
            ],
        ]);
    }

    // GET /franquia/parceiros/{id}
    public function show(int $id)
    {
        $parceiro = Parceiro::with('servicos')->where('active', true)->findOrFail($id);

        return response()->json(['data' => [
            'id'           => $parceiro->id,
            'nome_empresa' => $parceiro->nome_empresa ?: $parceiro->razao_social,
            'razao_social' => $parceiro->razao_social,
            'categoria'    => $parceiro->categoria,
            'descricao'    => $parceiro->descricao,
            'cidade'       => $parceiro->cidade,
            'estado'       => $parceiro->estado,
            'email'        => $parceiro->email,
            'telefone'     => $parceiro->telefone,
            'site'         => null,
            'active'       => $parceiro->active,
        ]]);
    }
}
