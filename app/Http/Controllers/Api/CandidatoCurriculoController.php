<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CandidatoCurriculoController extends Controller
{
    private function candidatoDoUsuario(): Candidato
    {
        return Candidato::where('user_id', auth()->id())->firstOrFail();
    }

    // GET /candidato/curriculos
    public function index()
    {
        $c = $this->candidatoDoUsuario();
        $docs = $c->documentos()
            ->where('tipo', 'curriculo')
            ->orderByDesc('created_at')
            ->get();
        return response()->json(['data' => $docs]);
    }

    // POST /candidato/curriculos
    public function store(Request $request)
    {
        $request->validate([
            'arquivo' => 'required|file|mimes:pdf,doc,docx|max:5120',
        ]);

        $c = $this->candidatoDoUsuario();
        $file = $request->file('arquivo');
        $path = $file->store('candidatos/curriculos', 'public');

        $primeiro = $c->documentos()->where('tipo', 'curriculo')->count() === 0;

        $doc = CandidatoDocumento::create([
            'candidato_id' => $c->id,
            'tipo'         => 'curriculo',
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => (int) round($file->getSize() / 1024),
            'ativo'        => $primeiro, // primeiro currículo vira automaticamente ativo
        ]);

        return response()->json(['data' => $doc], 201);
    }

    // PUT /candidato/curriculos/{id}/ativar
    public function ativar($id)
    {
        $c = $this->candidatoDoUsuario();
        $doc = $c->documentos()->where('tipo', 'curriculo')->findOrFail($id);

        DB::transaction(function () use ($c, $doc) {
            $c->documentos()->where('tipo', 'curriculo')->update(['ativo' => false]);
            $doc->update(['ativo' => true]);
        });

        return response()->json(['data' => $doc->fresh()]);
    }

    // DELETE /candidato/curriculos/{id}
    public function destroy($id)
    {
        $c = $this->candidatoDoUsuario();
        $doc = $c->documentos()->where('tipo', 'curriculo')->findOrFail($id);

        if (Storage::disk('public')->exists($doc->arquivo_path)) {
            Storage::disk('public')->delete($doc->arquivo_path);
        }
        $doc->delete();

        // Se o excluído era o ativo, ativa o mais recente restante
        if ($doc->ativo) {
            $outro = $c->documentos()
                ->where('tipo', 'curriculo')
                ->orderByDesc('created_at')
                ->first();
            $outro?->update(['ativo' => true]);
        }

        return response()->json(null, 204);
    }
}
