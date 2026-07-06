<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CandidatoPerfilController extends Controller
{
    private function candidatoDoUsuario(): Candidato
    {
        return Candidato::where('user_id', auth()->id())->firstOrFail();
    }

    // GET /candidato/perfil
    public function show()
    {
        $c = $this->candidatoDoUsuario();
        $c->load('user:id,name,email,phone');

        return response()->json(['data' => $this->comCurriculo($c)]);
    }

    /**
     * Anexa curriculo_nome/curriculo_url ao array do candidato — usado tanto no show()
     * quanto no update(), pra o front não perder esses campos ao salvar o perfil.
     */
    private function comCurriculo(Candidato $c): array
    {
        $doc = CandidatoDocumento::where('candidato_id', $c->id)
            ->where('tipo', 'curriculo')
            ->where('ativo', true)
            ->latest()
            ->first();

        $data = $c->toArray();
        $data['curriculo_nome'] = $doc?->arquivo_nome;
        $data['curriculo_url']  = $doc ? Storage::disk('public')->url($doc->arquivo_path) : null;

        return $data;
    }

    // PUT /candidato/perfil
    public function update(Request $request)
    {
        $c = $this->candidatoDoUsuario();

        $validated = $request->validate([
            'cpf'                      => 'nullable|string|max:14',
            'nascimento'               => 'nullable|date',
            'telefone'                 => 'nullable|string|max:20',
            'cep'                      => 'nullable|string|max:9',
            'rua'                      => 'nullable|string|max:255',
            'logradouro'               => 'nullable|string|max:255',
            'numero'                   => 'nullable|string|max:20',
            'complemento'              => 'nullable|string|max:100',
            'bairro'                   => 'nullable|string|max:100',
            'cidade'                   => 'nullable|string|max:100',
            'estado'                   => 'nullable|string|size:2',
            'tipo_cnh'                 => 'nullable|string|max:10',
            'cargo_desejado'           => 'nullable|string|max:255',
            'apresentacao'             => 'nullable|string',
            'linkedin'                 => 'nullable|url|max:255',
            'github'                   => 'nullable|url|max:255',
            'portfolio_url'            => 'nullable|url|max:255',
            'pretensao_salarial'       => 'nullable|numeric|min:0',
            'disponibilidade'          => 'nullable|in:imediata,15_dias,30_dias',
            'pcd'                      => 'nullable|boolean',
            'latitude'                 => 'nullable|numeric',
            'longitude'                => 'nullable|numeric',
            'experiencia_profissional' => 'nullable|string',
            'educacao'                 => 'nullable|string',
            'habilidades'              => 'nullable|string',
            'idiomas'                  => 'nullable|string|max:500',
            'cargos_interesse'         => 'nullable|array',
            'cargos_interesse.*'       => 'string|max:100',
            'name'                     => 'nullable|string|max:255',
        ]);

        // "logradouro" é o alias usado no front; mapeia para "rua"
        if (isset($validated['logradouro'])) {
            $validated['rua'] = $validated['logradouro'];
            unset($validated['logradouro']);
        }

        $c->update(collect($validated)->except('name')->toArray());

        if (!empty($validated['name']) && $c->user) {
            $c->user->update(['name' => $validated['name']]);
        }

        return response()->json([
            'message'   => 'Perfil atualizado.',
            'candidato' => $this->comCurriculo($c->fresh()->load('user:id,name,email,phone')),
        ]);
    }

    // POST /candidato/perfil/foto
    public function uploadFoto(Request $request)
    {
        $request->validate(['foto' => 'required|image|max:2048']);
        $c = $this->candidatoDoUsuario();

        // Remove foto antiga
        if ($c->foto_url) {
            $base = Storage::disk('public')->url('');
            $oldPath = str_replace($base, '', $c->foto_url);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('foto')->store('candidatos/fotos', 'public');
        $url  = Storage::disk('public')->url($path);
        $c->update(['foto_url' => $url]);

        return response()->json(['foto' => $url]);
    }

    // POST /candidato/perfil/curriculo
    public function uploadCurriculo(Request $request)
    {
        $request->validate(['arquivo' => 'required|file|mimes:pdf,doc,docx|max:10240']);
        $c = $this->candidatoDoUsuario();

        // Desativa o curriculo anterior (se existir)
        $antigo = CandidatoDocumento::where('candidato_id', $c->id)
            ->where('tipo', 'curriculo')
            ->where('ativo', true)
            ->first();

        if ($antigo) {
            Storage::disk('public')->delete($antigo->arquivo_path);
            $antigo->update(['ativo' => false]);
        }

        $file = $request->file('arquivo');
        $path = $file->store("candidatos/{$c->id}", 'public');

        $doc = CandidatoDocumento::create([
            'candidato_id' => $c->id,
            'tipo'         => 'curriculo',
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => (int) round($file->getSize() / 1024),
            'ativo'        => true,
        ]);

        return response()->json([
            'curriculo_nome' => $doc->arquivo_nome,
            'curriculo_url'  => Storage::disk('public')->url($doc->arquivo_path),
        ]);
    }
}
