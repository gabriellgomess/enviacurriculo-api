<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Franquia;
use App\Models\FranquiaDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FranquiaDocumentoController extends Controller
{
    public function index(Franquia $franquia)
    {
        return response()->json($franquia->documentos()->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request, Franquia $franquia)
    {
        $request->validate([
            'tipo'    => 'required|in:pessoal,empresa',
            'arquivo' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
        ]);

        $file     = $request->file('arquivo');
        $path     = $file->store("franquias/{$franquia->id}/documentos", 'public');
        $tamanho  = (int) round($file->getSize() / 1024);

        $doc = $franquia->documentos()->create([
            'tipo'         => $request->tipo,
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => $tamanho,
        ]);

        return response()->json($doc, 201);
    }

    public function destroy(Franquia $franquia, FranquiaDocumento $documento)
    {
        if ($documento->franquia_id !== $franquia->id) {
            return response()->json(['message' => 'Documento não pertence a esta franquia.'], 403);
        }

        Storage::disk('public')->delete($documento->arquivo_path);
        $documento->delete();

        return response()->json(['message' => 'Documento removido com sucesso.']);
    }

    public function download(Franquia $franquia, FranquiaDocumento $documento)
    {
        if ($documento->franquia_id !== $franquia->id) {
            return response()->json(['message' => 'Documento não pertence a esta franquia.'], 403);
        }

        if (!Storage::disk('public')->exists($documento->arquivo_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($documento->arquivo_path, $documento->arquivo_nome);
    }
}
