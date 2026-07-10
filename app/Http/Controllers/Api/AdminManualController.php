<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FranquiaManual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminManualController extends Controller
{
    // GET /api/admin/manuais
    public function index()
    {
        $manuais = FranquiaManual::orderByDesc('created_at')->get();
        return response()->json(['data' => $manuais]);
    }

    // POST /api/admin/manuais
    public function store(Request $request)
    {
        $request->validate([
            'titulo'  => 'required|string|max:255',
            'arquivo' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png,gif,webp',
        ]);

        $file    = $request->file('arquivo');
        $path    = $file->store('manuais', 'public');
        $tamanho = (int) ceil($file->getSize() / 1024);

        $manual = FranquiaManual::create([
            'titulo'       => $request->titulo,
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => $tamanho,
            'active'       => true,
        ]);

        return response()->json(['message' => 'Manual cadastrado com sucesso.', 'data' => $manual], 201);
    }

    // DELETE /api/admin/manuais/{id}
    public function destroy(int $id)
    {
        $manual = FranquiaManual::findOrFail($id);

        Storage::disk('public')->delete($manual->arquivo_path);
        $manual->delete();

        return response()->json(['message' => 'Manual removido com sucesso.']);
    }

    // PATCH /api/admin/manuais/{id}/toggle
    public function toggleActive(int $id)
    {
        $manual = FranquiaManual::findOrFail($id);
        $manual->update(['active' => !$manual->active]);

        return response()->json(['message' => 'Status alterado.', 'data' => $manual]);
    }

    // PUT /api/admin/manuais/{id}
    public function update(Request $request, int $id)
    {
        $manual = FranquiaManual::findOrFail($id);
        
        $request->validate([
            'titulo'  => 'required|string|max:255',
            'arquivo' => 'nullable|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png,gif,webp',
        ]);

        $data = [
            'titulo' => $request->titulo,
        ];

        if ($request->hasFile('arquivo')) {
            // Delete old file
            Storage::disk('public')->delete($manual->arquivo_path);
            
            // Store new file
            $file = $request->file('arquivo');
            $path = $file->store('manuais', 'public');
            $tamanho = (int) ceil($file->getSize() / 1024);

            $data['arquivo_path'] = $path;
            $data['arquivo_nome'] = $file->getClientOriginalName();
            $data['tamanho_kb']   = $tamanho;
        }

        $manual->update($data);

        return response()->json(['message' => 'Manual atualizado com sucesso.', 'data' => $manual]);
    }
}
