<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaFaturamento;
use Illuminate\Http\Request;

class AdminEmpresaFaturamentoController extends Controller
{
    // GET /admin/empresas/{empresa}/faturamentos
    public function index(Empresa $empresa)
    {
        $faturamentos = EmpresaFaturamento::where('empresa_id', $empresa->id)
            ->orderByDesc('ano')
            ->orderByDesc('mes')
            ->get();

        return response()->json(['data' => $faturamentos]);
    }

    // POST /admin/empresas/{empresa}/faturamentos
    // Cria ou atualiza (upsert) o valor do mês/ano informado.
    public function store(Request $request, Empresa $empresa)
    {
        $validated = $request->validate([
            'mes'   => 'required|integer|min:1|max:12',
            'ano'   => 'required|integer|min:2000|max:2100',
            'valor' => 'required|numeric|min:0',
        ]);

        $faturamento = EmpresaFaturamento::updateOrCreate(
            ['empresa_id' => $empresa->id, 'mes' => $validated['mes'], 'ano' => $validated['ano']],
            ['valor' => $validated['valor']]
        );

        return response()->json([
            'message' => 'Faturamento registrado.',
            'data'    => $faturamento,
        ], 201);
    }

    // PUT /admin/empresas/{empresa}/faturamentos/{id}
    public function update(Request $request, Empresa $empresa, int $id)
    {
        $faturamento = EmpresaFaturamento::where('empresa_id', $empresa->id)->findOrFail($id);

        $validated = $request->validate([
            'mes'   => 'required|integer|min:1|max:12',
            'ano'   => 'required|integer|min:2000|max:2100',
            'valor' => 'required|numeric|min:0',
        ]);

        // Evita duplicar mês/ano já existente em outro registro
        $existe = EmpresaFaturamento::where('empresa_id', $empresa->id)
            ->where('mes', $validated['mes'])
            ->where('ano', $validated['ano'])
            ->where('id', '!=', $faturamento->id)
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'Já existe um registro para este mês/ano.'], 422);
        }

        $faturamento->update($validated);

        return response()->json([
            'message' => 'Faturamento atualizado.',
            'data'    => $faturamento,
        ]);
    }

    // DELETE /admin/empresas/{empresa}/faturamentos/{id}
    public function destroy(Empresa $empresa, int $id)
    {
        EmpresaFaturamento::where('empresa_id', $empresa->id)->findOrFail($id)->delete();

        return response()->json(['message' => 'Faturamento excluído.']);
    }
}
