<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    // POST /admin/candidatos  (insere curriculo no banco global pelo admin)
    public function store(Request $request)
    {
        $data = $request->validate([
            'nome'                     => 'required|string|max:255',
            'email'                    => 'nullable|email|max:255',
            'telefone'                 => 'nullable|string|max:20',
            'cep'                      => 'nullable|string|max:9',
            'rua'                      => 'nullable|string|max:255',
            'numero'                   => 'nullable|string|max:20',
            'bairro'                   => 'nullable|string|max:100',
            'complemento'              => 'nullable|string|max:100',
            'cidade'                   => 'nullable|string|max:100',
            'uf'                       => 'nullable|string|size:2',
            'tipo_cnh'                 => 'nullable|string|max:10',
            'status'                   => 'nullable|in:ativo,inativo',
            'cargos_interesse'         => 'nullable|array|max:8',
            'cargos_interesse.*'       => 'string|max:100',
            'informacoes_pessoais'     => 'nullable|string',
            'experiencia_profissional' => 'nullable|string',
            'educacao'                 => 'nullable|string',
            'habilidades'              => 'nullable|string',
            'idiomas'                  => 'nullable|string|max:500',
            'informacoes_adicionais'   => 'nullable|string',
            'arquivo'                  => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'arquivo_cnh'              => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'arquivo_ctps'             => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'arquivos_diploma'         => 'nullable|array',
            'arquivos_diploma.*'       => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Já existe um usuário com este e-mail.'], 422);
        }

        $candidato = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name'     => $data['nome'],
                'email'    => $data['email'] ?? ('cv_' . Str::uuid() . '@banco.local'),
                'password' => Hash::make(Str::random(40)),
                'active'   => false,
            ]);

            $cargos = $data['cargos_interesse'] ?? null;

            $candidato = Candidato::create([
                'user_id'                  => $user->id,
                'franquia_id'              => null, // inserido pelo admin (banco global)
                'criado_por'               => $request->user()?->id,
                'telefone'                 => $data['telefone'] ?? null,
                'cep'                      => $data['cep'] ?? null,
                'rua'                      => $data['rua'] ?? null,
                'numero'                   => $data['numero'] ?? null,
                'bairro'                   => $data['bairro'] ?? null,
                'complemento'              => $data['complemento'] ?? null,
                'cidade'                   => $data['cidade'] ?? null,
                'estado'                   => $data['uf'] ?? null,
                'tipo_cnh'                 => $data['tipo_cnh'] ?? null,
                'active'                   => ($data['status'] ?? 'ativo') === 'ativo',
                'cargo_desejado'           => $cargos ? ($cargos[0] ?? null) : null,
                'cargos_interesse'         => $cargos,
                'apresentacao'             => $data['informacoes_pessoais'] ?? null,
                'experiencia_profissional' => $data['experiencia_profissional'] ?? null,
                'educacao'                 => $data['educacao'] ?? null,
                'habilidades'              => $data['habilidades'] ?? null,
                'idiomas'                  => $data['idiomas'] ?? null,
                'informacoes_adicionais'   => $data['informacoes_adicionais'] ?? null,
            ]);

            $uploads = ['arquivo' => 'curriculo', 'arquivo_cnh' => 'cnh', 'arquivo_ctps' => 'ctps'];
            foreach ($uploads as $campo => $tipo) {
                if ($request->hasFile($campo)) {
                    $this->salvarDocumento($candidato, $request->file($campo), $tipo);
                }
            }
            if ($request->hasFile('arquivos_diploma')) {
                foreach ($request->file('arquivos_diploma') as $file) {
                    $this->salvarDocumento($candidato, $file, 'diploma');
                }
            }

            return $candidato;
        });

        return response()->json([
            'message' => 'Currículo inserido com sucesso.',
            'data'    => ['id' => $candidato->id, 'nome' => $data['nome']],
        ], 201);
    }

    private function salvarDocumento(Candidato $candidato, $file, string $tipo): void
    {
        CandidatoDocumento::create([
            'candidato_id' => $candidato->id,
            'tipo'         => $tipo,
            'arquivo_path' => $file->store("candidatos/{$candidato->id}", 'public'),
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => (int) round($file->getSize() / 1024),
            'ativo'        => true,
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
