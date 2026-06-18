<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\EmpresaBibliotecaDocumento;
use App\Models\EmpresaBibliotecaTipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmpresaBibliotecaController extends Controller
{
    use HasTokenContext;

    // GET /empresa/biblioteca/tipos
    public function tiposIndex(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $tipos = EmpresaBibliotecaTipo::where('empresa_id', $empresaId)
            ->orderBy('nome')
            ->get(['id', 'nome']);

        return response()->json(['data' => $tipos]);
    }

    // POST /empresa/biblioteca/tipos
    public function tiposStore(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $data = $request->validate(['nome' => 'required|string|max:255']);

        $tipo = EmpresaBibliotecaTipo::create([
            'empresa_id' => $empresaId,
            'nome'       => $data['nome'],
        ]);

        return response()->json($tipo->only(['id', 'nome']), 201);
    }

    // DELETE /empresa/biblioteca/tipos/{id}
    public function tiposDestroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $tipo = EmpresaBibliotecaTipo::where('empresa_id', $empresaId)->findOrFail($id);
        $tipo->delete();

        return response()->noContent();
    }

    // GET /empresa/biblioteca/documentos
    public function documentosIndex(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $query = EmpresaBibliotecaDocumento::where('empresa_id', $empresaId)
            ->with('tipo:id,nome')
            ->orderByDesc('created_at');

        if ($request->filled('tipo_id')) {
            $query->where('tipo_id', $request->tipo_id);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(fn($q) => $q->where('nome', 'like', $term)->orWhere('arquivo_nome', 'like', $term));
        }

        $documentos = $query->get();

        return response()->json([
            'data' => $documentos,
            'meta' => ['total' => $documentos->count()],
        ]);
    }

    // POST /empresa/biblioteca/documentos
    public function documentosStore(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $request->validate([
            'arquivo' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png',
            'nome'    => 'required|string|max:255',
            'tipo_id' => 'nullable|integer',
        ]);

        // garante que o tipo (se informado) pertence a esta empresa
        $tipoId = null;
        if ($request->filled('tipo_id')) {
            $tipoId = EmpresaBibliotecaTipo::where('empresa_id', $empresaId)
                ->where('id', $request->tipo_id)
                ->value('id');
        }

        $file    = $request->file('arquivo');
        $path    = $file->store("empresas/{$empresaId}/biblioteca", 'public');
        $tamanho = (int) ceil($file->getSize() / 1024);

        $documento = EmpresaBibliotecaDocumento::create([
            'empresa_id'   => $empresaId,
            'tipo_id'      => $tipoId,
            'nome'         => $request->nome,
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => $tamanho,
        ]);

        return response()->json($documento->load('tipo:id,nome'), 201);
    }

    // DELETE /empresa/biblioteca/documentos/{id}
    public function documentosDestroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $documento = EmpresaBibliotecaDocumento::where('empresa_id', $empresaId)->findOrFail($id);

        Storage::disk('public')->delete($documento->arquivo_path);
        $documento->delete();

        return response()->noContent();
    }

    // GET /empresa/biblioteca/documentos/{id}/download
    public function documentosDownload(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $documento = EmpresaBibliotecaDocumento::where('empresa_id', $empresaId)->findOrFail($id);

        if (!Storage::disk('public')->exists($documento->arquivo_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($documento->arquivo_path, $documento->arquivo_nome);
    }
}
