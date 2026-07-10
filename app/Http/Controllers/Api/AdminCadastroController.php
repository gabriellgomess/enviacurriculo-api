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
            'tipo'       => 'nullable|in:credito,avulso,recorrente',
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
            'tipo'       => 'required|in:credito,avulso,recorrente',
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
            'nome'       => 'required|string|max:255',
            'cnpj'       => 'nullable|string|max:18',
            'email'      => 'nullable|email',
            'telefone'   => 'nullable|string|max:20',
            'endereco'   => 'nullable|string|max:255',
            'observacao' => 'nullable|string',
            'ativo'      => 'nullable|boolean',
            'categoria'  => 'nullable|string|max:50',
        ]);

        $fornecedor = FranquiaFornecedor::create($validated + [
            'franquia_id' => $this->getFranquiaId(),
            'ativo'       => $validated['ativo'] ?? true,
        ]);

        return response()->json(['message' => 'Fornecedor cadastrado com sucesso.', 'data' => $fornecedor], 201);
    }

    // PUT /api/admin/cadastro/fornecedores/{id}
    public function updateFornecedor(Request $request, int $id)
    {
        $fornecedor = FranquiaFornecedor::findOrFail($id);

        $validated = $request->validate([
            'nome'       => 'required|string|max:255',
            'cnpj'       => 'nullable|string|max:18',
            'email'      => 'nullable|email',
            'telefone'   => 'nullable|string|max:20',
            'endereco'   => 'nullable|string|max:255',
            'observacao' => 'nullable|string',
            'ativo'      => 'required|boolean',
            'categoria'  => 'nullable|string|max:50',
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

    // GET /api/admin/cadastro/tipos-contrato
    public function indexTiposContrato()
    {
        $tipos = \App\Models\TipoContrato::orderBy('nome')->get();
        return response()->json(['data' => $tipos]);
    }

    // POST /api/admin/cadastro/tipos-contrato
    public function storeTipoContrato(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:100',
            'active' => 'nullable|boolean',
        ]);

        $slug = \Illuminate\Support\Str::slug($validated['nome']);
        if (\App\Models\TipoContrato::where('slug', $slug)->exists()) {
            return response()->json(['message' => 'Um tipo de contratação com este nome ou similar já existe.'], 422);
        }

        $tipo = \App\Models\TipoContrato::create([
            'nome' => $validated['nome'],
            'slug' => $slug,
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json(['message' => 'Tipo de contratação criado com sucesso.', 'data' => $tipo], 201);
    }

    // PUT /api/admin/cadastro/tipos-contrato/{id}
    public function updateTipoContrato(Request $request, int $id)
    {
        $tipo = \App\Models\TipoContrato::findOrFail($id);

        $validated = $request->validate([
            'nome' => 'required|string|max:100',
            'active' => 'required|boolean',
        ]);

        $slug = \Illuminate\Support\Str::slug($validated['nome']);
        if (\App\Models\TipoContrato::where('slug', $slug)->where('id', '!=', $id)->exists()) {
            return response()->json(['message' => 'Um tipo de contratação com este nome ou similar já existe.'], 422);
        }

        $tipo->update([
            'nome' => $validated['nome'],
            'slug' => $slug,
            'active' => $validated['active'],
        ]);

        return response()->json(['message' => 'Tipo de contratação atualizado com sucesso.', 'data' => $tipo]);
    }

    // DELETE /api/admin/cadastro/tipos-contrato/{id}
    public function destroyTipoContrato(int $id)
    {
        $tipo = \App\Models\TipoContrato::findOrFail($id);

        if (\App\Models\Vaga::where('tipo_contrato', $tipo->slug)->exists()) {
            return response()->json(['message' => 'Este tipo de contratação está sendo utilizado por vagas e não pode ser excluído.'], 422);
        }

        $tipo->delete();

        return response()->json(['message' => 'Tipo de contratação excluído com sucesso.']);
    }
}
