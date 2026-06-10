<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotaFiscal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminNotaFiscalController extends Controller
{
    public function index(Request $request)
    {
        $query = AdminNotaFiscal::query()->orderByDesc('data_emissao');

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['created_by'] = $request->user()->id;

        if ($request->hasFile('arquivo')) {
            $data = [...$data, ...$this->storeArquivo($request)];
        }

        $nota = AdminNotaFiscal::create($data);

        return response()->json($nota, 201);
    }

    public function update(Request $request, AdminNotaFiscal $nota)
    {
        $data = $this->validateData($request);

        if ($request->hasFile('arquivo')) {
            if ($nota->arquivo_path) {
                Storage::disk('public')->delete($nota->arquivo_path);
            }
            $data = [...$data, ...$this->storeArquivo($request)];
        }

        $nota->update($data);

        return response()->json($nota);
    }

    public function destroy(AdminNotaFiscal $nota)
    {
        if ($nota->arquivo_path) {
            Storage::disk('public')->delete($nota->arquivo_path);
        }

        $nota->delete();

        return response()->json(['message' => 'Nota fiscal excluída com sucesso.']);
    }

    public function download(AdminNotaFiscal $nota)
    {
        if (!$nota->arquivo_path || !Storage::disk('public')->exists($nota->arquivo_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($nota->arquivo_path, $nota->arquivo_nome);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'tipo'            => 'required|in:emitida,recebida',
            'numero'          => 'required|string|max:50',
            'razao_social'    => 'required|string|max:255',
            'cnpj_cpf'        => 'nullable|string|max:20',
            'valor'           => 'required|numeric|min:0',
            'data_emissao'    => 'required|date',
            'data_vencimento' => 'nullable|date',
            'descricao'       => 'nullable|string',
            'status'          => 'required|in:pendente,paga,cancelada',
            'arquivo'         => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png',
        ]);
    }

    private function storeArquivo(Request $request): array
    {
        $file = $request->file('arquivo');

        return [
            'arquivo_path' => $file->store('admin/notas-fiscais', 'public'),
            'arquivo_nome' => $file->getClientOriginalName(),
        ];
    }
}
