<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\HasTokenContext;
use App\Models\ParceiroCategoria;
use App\Models\ParceiroServico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ParceiroServicoController extends Controller
{
    use HasTokenContext;

    // GET /parceiro/servicos
    public function index(Request $request)
    {
        $parceiroId = $this->tokenContextId($request);

        $servicos = ParceiroServico::where('parceiro_id', $parceiroId)
            ->with('categoria:id,nome')
            ->orderBy('nome_servico')
            ->get();

        return response()->json(['data' => $servicos]);
    }

    // GET /parceiro/categorias
    public function categorias()
    {
        return response()->json(['data' => ParceiroCategoria::orderBy('nome')->get()]);
    }

    // POST /parceiro/servicos
    public function store(Request $request)
    {
        $parceiroId = $this->tokenContextId($request);

        $validated = $request->validate([
            'nome_servico'  => 'required|string|max:255',
            'descricao'     => 'nullable|string',
            'categoria_id'  => 'required|exists:parceiros_categorias,id',
            'proposta_file' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:10240',
        ]);

        $propostaUrl = null;
        if ($request->hasFile('proposta_file')) {
            $path = $request->file('proposta_file')->store('parceiros/propostas', 'public');
            $propostaUrl = asset('storage/' . $path);
        }

        $servico = ParceiroServico::create([
            'parceiro_id'  => $parceiroId,
            'nome_servico' => $validated['nome_servico'],
            'descricao'    => $validated['descricao'] ?? null,
            'categoria_id' => $validated['categoria_id'],
            'proposta_url' => $propostaUrl,
        ]);

        return response()->json(['data' => $servico->load('categoria:id,nome')], 201);
    }

    // PUT /parceiro/servicos/{id}
    public function update(Request $request, $id)
    {
        $parceiroId = $this->tokenContextId($request);
        $servico = ParceiroServico::where('parceiro_id', $parceiroId)->findOrFail($id);

        $validated = $request->validate([
            'nome_servico'  => 'sometimes|required|string|max:255',
            'descricao'     => 'nullable|string',
            'categoria_id'  => 'sometimes|required|exists:parceiros_categorias,id',
            'proposta_file' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:10240',
        ]);

        $servico->update([
            'nome_servico' => $validated['nome_servico'] ?? $servico->nome_servico,
            'descricao'    => $request->has('descricao') ? $validated['descricao'] : $servico->descricao,
            'categoria_id' => $validated['categoria_id'] ?? $servico->categoria_id,
        ]);

        if ($request->hasFile('proposta_file')) {
            if ($servico->proposta_url) {
                $oldPath = str_replace(asset('storage/'), '', $servico->proposta_url);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }
            $path = $request->file('proposta_file')->store('parceiros/propostas', 'public');
            $servico->proposta_url = asset('storage/' . $path);
            $servico->save();
        }

        return response()->json(['data' => $servico->load('categoria:id,nome')]);
    }

    // DELETE /parceiro/servicos/{id}
    public function destroy(Request $request, $id)
    {
        $parceiroId = $this->tokenContextId($request);
        $servico = ParceiroServico::where('parceiro_id', $parceiroId)->findOrFail($id);

        if ($servico->proposta_url) {
            $oldPath = str_replace(asset('storage/'), '', $servico->proposta_url);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $servico->delete();

        return response()->noContent();
    }
}
