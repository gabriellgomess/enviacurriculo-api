<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class CandidatoController extends Controller
{
    public function index(Request $request)
    {
        $query = Candidato::with('user:id,name,email,phone,active');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('cpf', 'like', "%{$s}%")
                  ->orWhere('cargo_desejado', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")
                  );
            });
        }

        if ($request->filled('active')) {
            $query->where('active', $request->active === '1' || $request->active === 'true');
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $candidatos = $query->orderByDesc('created_at')->paginate(20);

        $meta = [
            'total'   => Candidato::count(),
            'ativos'  => Candidato::where('active', true)->count(),
            'inativos'=> Candidato::where('active', false)->count(),
        ];

        return response()->json([
            'data' => $candidatos->items(),
            'meta' => array_merge($candidatos->toArray(), $meta),
        ]);
    }

    public function show(Candidato $candidato)
    {
        return response()->json(
            $candidato->load(['user:id,name,email,phone,active', 'documentos'])
        );
    }

    public function update(Request $request, Candidato $candidato)
    {
        $validated = $request->validate([
            'cpf'                      => 'nullable|string|max:14|unique:candidatos,cpf,' . $candidato->id,
            'nascimento'               => 'nullable|date',
            'telefone'                 => 'nullable|string|max:20',
            'cargo_desejado'           => 'nullable|string|max:255',
            'cep'                      => 'nullable|string|max:9',
            'rua'                      => 'nullable|string|max:255',
            'numero'                   => 'nullable|string|max:20',
            'complemento'              => 'nullable|string|max:100',
            'bairro'                   => 'nullable|string|max:100',
            'cidade'                   => 'nullable|string|max:100',
            'estado'                   => 'nullable|string|size:2',
            'tipo_cnh'                 => 'nullable|string|max:10',
            'experiencia_profissional' => 'nullable|string',
            'educacao'                 => 'nullable|string',
            'habilidades'              => 'nullable|string',
            'active'                   => 'nullable|boolean',
            // dados do usuário
            'name'                     => 'nullable|string|max:255',
            'email'                    => 'nullable|email|max:255|unique:users,email,' . $candidato->user_id,
            'password'                 => 'nullable|string|min:6',
        ]);

        $candidato->update([
            'cpf'                      => $validated['cpf']                      ?? $candidato->cpf,
            'nascimento'               => $validated['nascimento']               ?? $candidato->nascimento,
            'telefone'                 => $validated['telefone']                 ?? $candidato->telefone,
            'cargo_desejado'           => $validated['cargo_desejado']           ?? $candidato->cargo_desejado,
            'cep'                      => $validated['cep']                      ?? $candidato->cep,
            'rua'                      => $validated['rua']                      ?? $candidato->rua,
            'numero'                   => $validated['numero']                   ?? $candidato->numero,
            'complemento'              => $validated['complemento']              ?? $candidato->complemento,
            'bairro'                   => $validated['bairro']                   ?? $candidato->bairro,
            'cidade'                   => $validated['cidade']                   ?? $candidato->cidade,
            'estado'                   => $validated['estado']                   ?? $candidato->estado,
            'tipo_cnh'                 => $validated['tipo_cnh']                 ?? $candidato->tipo_cnh,
            'experiencia_profissional' => $validated['experiencia_profissional'] ?? $candidato->experiencia_profissional,
            'educacao'                 => $validated['educacao']                 ?? $candidato->educacao,
            'habilidades'              => $validated['habilidades']              ?? $candidato->habilidades,
            'active'                   => $validated['active']                   ?? $candidato->active,
        ]);

        $user = $candidato->user;
        if ($user) {
            $userUpdate = [];
            if (isset($validated['name']))  $userUpdate['name']  = $validated['name'];
            if (isset($validated['email'])) $userUpdate['email'] = $validated['email'];
            if (!empty($validated['password'])) $userUpdate['password'] = Hash::make($validated['password']);
            if (!empty($userUpdate)) $user->update($userUpdate);
        }

        return response()->json($candidato->fresh()->load(['user:id,name,email,phone,active', 'documentos']));
    }

    public function destroy(Candidato $candidato)
    {
        $candidato->delete();
        return response()->json(['message' => 'Candidato removido com sucesso.']);
    }

    public function toggleActive(Candidato $candidato)
    {
        $candidato->update(['active' => !$candidato->active]);
        $candidato->user?->update(['active' => $candidato->active]);
        return response()->json($candidato->fresh()->load('user:id,name,email,phone,active'));
    }

    public function downloadDocumento(Candidato $candidato, CandidatoDocumento $documento)
    {
        if ($documento->candidato_id !== $candidato->id) {
            return response()->json(['message' => 'Documento não pertence a este candidato.'], 403);
        }

        if (!Storage::disk('public')->exists($documento->arquivo_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($documento->arquivo_path, $documento->arquivo_nome);
    }
}
