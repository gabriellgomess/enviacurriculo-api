<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\HasTokenContext;
use App\Models\Parceiro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ParceiroPerfilController extends Controller
{
    use HasTokenContext;

    // GET /parceiro/perfil
    public function show(Request $request)
    {
        $parceiro = Parceiro::findOrFail($this->tokenContextId($request));
        return response()->json(['data' => $parceiro]);
    }

    // PUT /parceiro/perfil
    public function update(Request $request)
    {
        $parceiro = Parceiro::findOrFail($this->tokenContextId($request));

        $validated = $request->validate([
            'nome_empresa'   => 'sometimes|required|string|max:255',
            'email'          => 'nullable|email|max:255',
            'telefone'       => 'nullable|string|max:20',
            'descricao'      => 'nullable|string',
            'logo_url'       => 'nullable|url|max:500',
            'cep'            => 'nullable|string|max:9',
            'rua'            => 'nullable|string|max:255',
            'numero'         => 'nullable|string|max:20',
            'bairro'         => 'nullable|string|max:100',
            'cidade'         => 'nullable|string|max:100',
            'estado'         => 'nullable|string|size:2',
            'especialidades' => 'nullable|array',
            'especialidades.*' => 'string|max:100',
        ]);

        $parceiro->update($validated);

        return response()->json(['data' => $parceiro->fresh()]);
    }

    // POST /parceiro/perfil/logo
    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => 'required|image|max:2048']);
        $parceiro = Parceiro::findOrFail($this->tokenContextId($request));

        if ($parceiro->logo_url) {
            $base    = Storage::disk('public')->url('');
            $oldPath = str_replace($base, '', $parceiro->logo_url);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('logo')->store('parceiros/logos', 'public');
        $url  = Storage::disk('public')->url($path);
        $parceiro->update(['logo_url' => $url]);

        return response()->json(['logo_url' => $url]);
    }
}
