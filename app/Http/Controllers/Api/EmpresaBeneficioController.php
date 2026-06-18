<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\EmpresaColaboradorBeneficio;
use Illuminate\Http\Request;

class EmpresaBeneficioController extends Controller
{
    use HasTokenContext;

    private const CATEGORIAS = ['alimentacao', 'transporte', 'saude', 'familia', 'financeiro', 'qualidade_vida', 'educacao', 'outros'];

    private function rules(): array
    {
        return [
            'nome'      => 'required|string|max:255',
            'descricao' => 'nullable|string|max:1000',
            'categoria' => 'nullable|in:' . implode(',', self::CATEGORIAS),
            'valor'     => 'nullable|numeric|min:0',
            'ativo'     => 'nullable|boolean',
        ];
    }

    // GET /empresa/beneficios
    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $beneficios = EmpresaColaboradorBeneficio::where('empresa_id', $empresaId)
            ->orderBy('nome')
            ->get();

        return response()->json(['data' => $beneficios]);
    }

    // POST /empresa/beneficios
    public function store(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $data = $request->validate($this->rules());

        $beneficio = EmpresaColaboradorBeneficio::create(array_merge($data, [
            'empresa_id' => $empresaId,
            'categoria'  => $data['categoria'] ?? 'outros',
            'ativo'      => $data['ativo'] ?? true,
        ]));

        return response()->json($beneficio, 201);
    }

    // PUT /empresa/beneficios/{id}
    public function update(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $beneficio = EmpresaColaboradorBeneficio::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate($this->rules());
        $beneficio->update($data);

        return response()->json($beneficio);
    }

    // DELETE /empresa/beneficios/{id}
    public function destroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $beneficio = EmpresaColaboradorBeneficio::where('empresa_id', $empresaId)->findOrFail($id);
        $beneficio->delete();

        return response()->noContent();
    }
}
