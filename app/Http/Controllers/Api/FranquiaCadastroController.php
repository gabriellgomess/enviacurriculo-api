<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\FranquiaFornecedor;
use App\Models\FranquiaServico;
use Illuminate\Http\Request;

class FranquiaCadastroController extends Controller
{
    use HasTokenContext;

    // GET /franquia/cadastro/servicos
    public function indexServicos(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $servicos = FranquiaServico::where('franquia_id', $franquiaId)->get();
        return response()->json(['data' => $servicos]);
    }

    // POST /franquia/cadastro/servicos
    public function storeServico(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $validated = $request->validate([
            'nome'        => 'required|string|max:255',
            'descricao'   => 'nullable|string',
            'valor_base'  => 'nullable|numeric|min:0',
        ]);

        $servico = FranquiaServico::create(array_merge($validated, ['franquia_id' => $franquiaId]));

        return response()->json(['message' => 'Serviço criado.', 'data' => ['id' => $servico->id]], 201);
    }

    // PUT /franquia/cadastro/servicos/{id}
    public function updateServico(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $servico = FranquiaServico::where('franquia_id', $franquiaId)->findOrFail($id);

        $validated = $request->validate([
            'nome'        => 'sometimes|required|string|max:255',
            'descricao'   => 'nullable|string',
            'valor_base'  => 'nullable|numeric|min:0',
            'active'      => 'boolean',
        ]);

        $servico->update($validated);

        return response()->json(['message' => 'Serviço atualizado.']);
    }

    // DELETE /franquia/cadastro/servicos/{id}
    public function destroyServico(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        FranquiaServico::where('franquia_id', $franquiaId)->findOrFail($id)->delete();
        return response()->json(['message' => 'Serviço removido.']);
    }

    // GET /franquia/cadastro/fornecedores
    public function indexFornecedores(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $fornecedores = FranquiaFornecedor::where('franquia_id', $franquiaId)
            ->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $fornecedores->items(),
            'meta' => ['total' => $fornecedores->total(), 'per_page' => $fornecedores->perPage(),
                       'current_page' => $fornecedores->currentPage(), 'last_page' => $fornecedores->lastPage()],
        ]);
    }

    // POST /franquia/cadastro/fornecedores
    public function storeFornecedor(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $validated = $request->validate([
            'nome'       => 'required|string|max:255',
            'cnpj'       => 'nullable|string|max:18',
            'email'      => 'nullable|email|max:255',
            'telefone'   => 'nullable|string|max:20',
            'categoria'  => 'nullable|string|max:50',
            'endereco'   => 'nullable|string|max:255',
            'observacao' => 'nullable|string',
            'ativo'      => 'nullable|boolean',
        ]);

        $fornecedor = FranquiaFornecedor::create(array_merge($validated, ['franquia_id' => $franquiaId]));

        return response()->json(['message' => 'Fornecedor criado.', 'data' => ['id' => $fornecedor->id]], 201);
    }

    // PUT /franquia/cadastro/fornecedores/{id}
    public function updateFornecedor(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $fornecedor = FranquiaFornecedor::where('franquia_id', $franquiaId)->findOrFail($id);

        $validated = $request->validate([
            'nome'       => 'sometimes|required|string|max:255',
            'cnpj'       => 'nullable|string|max:18',
            'email'      => 'nullable|email|max:255',
            'telefone'   => 'nullable|string|max:20',
            'categoria'  => 'nullable|string|max:50',
            'endereco'   => 'nullable|string|max:255',
            'observacao' => 'nullable|string',
            'ativo'      => 'nullable|boolean',
        ]);

        $fornecedor->update($validated);

        return response()->json(['message' => 'Fornecedor atualizado.']);
    }

    // DELETE /franquia/cadastro/fornecedores/{id}
    public function destroyFornecedor(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        FranquiaFornecedor::where('franquia_id', $franquiaId)->findOrFail($id)->delete();
        return response()->json(['message' => 'Fornecedor removido.']);
    }
}
