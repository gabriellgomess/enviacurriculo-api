<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\FranquiaArquivo;
use App\Models\FranquiaManual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FranquiaBibliotecaController extends Controller
{
    use HasTokenContext;

    // GET /franquia/biblioteca/arquivos
    public function indexArquivos(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $arquivos = FranquiaArquivo::where('franquia_id', $franquiaId)
            ->orderByDesc('created_at')->get();

        return response()->json(['data' => $arquivos]);
    }

    // POST /franquia/biblioteca/arquivos
    public function storeArquivo(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $request->validate([
            'arquivo'   => 'required|file|max:20480|mimes:pdf,doc,docx,xlsx,jpg,jpeg,png',
            'nome'      => 'required|string|max:255',
            'categoria' => 'nullable|string|max:50',
        ]);

        $file   = $request->file('arquivo');
        $path   = $file->store("franquias/{$franquiaId}/biblioteca", 'public');
        $tamanho = (int) ceil($file->getSize() / 1024);

        $arquivo = FranquiaArquivo::create([
            'franquia_id'  => $franquiaId,
            'nome'         => $request->nome,
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => $tamanho,
            'categoria'    => $request->categoria,
        ]);

        return response()->json(['message' => 'Arquivo enviado com sucesso.', 'data' => ['id' => $arquivo->id]], 201);
    }

    // DELETE /franquia/biblioteca/arquivos/{id}
    public function destroyArquivo(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $arquivo = FranquiaArquivo::where('franquia_id', $franquiaId)->findOrFail($id);

        Storage::disk('public')->delete($arquivo->arquivo_path);
        $arquivo->delete();

        return response()->json(['message' => 'Arquivo removido.']);
    }

    // GET /franquia/biblioteca/arquivos/{id}/download
    public function downloadArquivo(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $arquivo = FranquiaArquivo::where('franquia_id', $franquiaId)->findOrFail($id);

        if (!Storage::disk('public')->exists($arquivo->arquivo_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($arquivo->arquivo_path, $arquivo->arquivo_nome);
    }

    // GET /franquia/biblioteca/manuais
    public function indexManuais(Request $request)
    {
        $manuais = FranquiaManual::where('active', true)->orderByDesc('updated_at')->get();
        return response()->json(['data' => $manuais]);
    }

    // GET /franquia/biblioteca/manuais/{id}/download
    public function downloadManual(Request $request, int $id)
    {
        $manual = FranquiaManual::where('active', true)->findOrFail($id);

        if (!Storage::disk('public')->exists($manual->arquivo_path)) {
            return response()->json(['message' => 'Manual não encontrado.'], 404);
        }

        return Storage::disk('public')->download($manual->arquivo_path, $manual->arquivo_nome);
    }
}
