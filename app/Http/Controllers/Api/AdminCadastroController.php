<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FranquiaServico;
use App\Models\FranquiaFornecedor;
use App\Models\Franquia;
use Illuminate\Http\Request;

class AdminCadastroController extends Controller
{
    private function getFranquiaId()
    {
        $franquia = Franquia::first();
        return $franquia ? $franquia->id : 1;
    }

    // GET /api/admin/cadastro/servicos
    public function indexServicos()
    {
        $servicos = FranquiaServico::orderBy('nome')->get();
        return response()->json(['data' => $servicos]);
    }

    // POST /api/admin/cadastro/servicos
    public function storeServico(Request $request)
    {
        $validated = $request->validate([
            'nome'       => 'required|string|max:255',
            'descricao'  => 'nullable|string',
            'valor_base' => 'nullable|numeric',
        ]);

        $servico = FranquiaServico::create($validated + [
            'franquia_id' => $this->getFranquiaId(),
            'active'      => true,
        ]);

        return response()->json(['message' => 'Serviço criado com sucesso.', 'data' => $servico], 201);
    }

    // PUT /api/admin/cadastro/servicos/{id}
    public function updateServico(Request $request, int $id)
    {
        $servico = FranquiaServico::findOrFail($id);

        $validated = $request->validate([
            'nome'       => 'required|string|max:255',
            'descricao'  => 'nullable|string',
            'valor_base' => 'nullable|numeric',
            'active'     => 'required|boolean',
        ]);

        $servico->update($validated);

        return response()->json(['message' => 'Serviço atualizado com sucesso.', 'data' => $servico]);
    }

    // DELETE /api/admin/cadastro/servicos/{id}
    public function destroyServico(int $id)
    {
        $servico = FranquiaServico::findOrFail($id);
        $servico->delete();

        return response()->json(['message' => 'Serviço excluído com sucesso.']);
    }

    // GET /api/admin/cadastro/fornecedores
    public function indexFornecedores()
    {
        $fornecedores = FranquiaFornecedor::orderBy('nome')->get();
        return response()->json(['data' => $fornecedores]);
    }

    // POST /api/admin/cadastro/fornecedores
    public function storeFornecedor(Request $request)
    {
        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'cnpj'      => 'nullable|string|max:18',
            'email'     => 'nullable|email',
            'telefone'  => 'nullable|string|max:20',
            'categoria' => 'nullable|string|max:50',
        ]);

        $fornecedor = FranquiaFornecedor::create($validated + [
            'franquia_id' => $this->getFranquiaId(),
        ]);

        return response()->json(['message' => 'Fornecedor cadastrado com sucesso.', 'data' => $fornecedor], 201);
    }

    // PUT /api/admin/cadastro/fornecedores/{id}
    public function updateFornecedor(Request $request, int $id)
    {
        $fornecedor = FranquiaFornecedor::findOrFail($id);

        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'cnpj'      => 'nullable|string|max:18',
            'email'     => 'nullable|email',
            'telefone'  => 'nullable|string|max:20',
            'categoria' => 'nullable|string|max:50',
        ]);

        $fornecedor->update($validated);

        return response()->json(['message' => 'Fornecedor atualizado com sucesso.', 'data' => $fornecedor]);
    }

    // DELETE /api/admin/cadastro/fornecedores/{id}
    public function destroyFornecedor(int $id)
    {
        $fornecedor = FranquiaFornecedor::findOrFail($id);
        $fornecedor->delete();

        return response()->json(['message' => 'Fornecedor excluído com sucesso.']);
    }
}
