<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class RegisterCandidatoController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'nome_completo'           => 'required|string|max:255',
            'email'                   => 'required|email|max:255|unique:users,email',
            'password'                => ['required', 'confirmed', Password::min(6)],
            'telefone'                => 'required|string|max:20',
            // Endereço
            'cep'                     => 'nullable|string|max:9',
            'rua'                     => 'nullable|string|max:255',
            'numero'                  => 'nullable|string|max:20',
            'complemento'             => 'nullable|string|max:100',
            'bairro'                  => 'nullable|string|max:100',
            'cidade'                  => 'nullable|string|max:100',
            'estado'                  => 'nullable|string|size:2',
            // Profissional
            'tipo_cnh'                => 'nullable|string|max:10',
            'experiencia_profissional'=> 'nullable|string',
            'educacao'                => 'nullable|string',
            'habilidades'             => 'nullable|string',
            // Arquivos
            'curriculo'               => 'required|file|max:10240|mimes:pdf,doc,docx,jpg,jpeg,png,webp',
            'cnh'                     => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,webp',
            'ctps'                    => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,webp',
            'diplomas'                => 'nullable|array',
            'diplomas.*'              => 'file|max:10240|mimes:pdf,jpg,jpeg,png,webp',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $user = User::create([
                'name'     => $validated['nome_completo'],
                'email'    => $validated['email'],
                'phone'    => $validated['telefone'],
                'password' => Hash::make($validated['password']),
                'active'   => true,
            ]);

            UserRole::create(['user_id' => $user->id, 'role' => 'candidato']);

            $candidato = Candidato::create([
                'user_id'                  => $user->id,
                'telefone'                 => $validated['telefone'],
                'cep'                      => $validated['cep'] ?? null,
                'rua'                      => $validated['rua'] ?? null,
                'numero'                   => $validated['numero'] ?? null,
                'complemento'              => $validated['complemento'] ?? null,
                'bairro'                   => $validated['bairro'] ?? null,
                'cidade'                   => $validated['cidade'] ?? null,
                'estado'                   => $validated['estado'] ?? null,
                'tipo_cnh'                 => $validated['tipo_cnh'] ?? null,
                'experiencia_profissional' => $validated['experiencia_profissional'] ?? null,
                'educacao'                 => $validated['educacao'] ?? null,
                'habilidades'              => $validated['habilidades'] ?? null,
                'active'                   => true,
            ]);

            UserContext::create([
                'user_id'    => $user->id,
                'role'       => 'candidato',
                'context_id' => $candidato->id,
            ]);

            // Salva currículo obrigatório
            $this->salvarArquivo($request->file('curriculo'), $candidato, 'curriculo');

            // Salva documentos opcionais
            if ($request->hasFile('cnh')) {
                $this->salvarArquivo($request->file('cnh'), $candidato, 'cnh');
            }
            if ($request->hasFile('ctps')) {
                $this->salvarArquivo($request->file('ctps'), $candidato, 'ctps');
            }
            if ($request->hasFile('diplomas')) {
                foreach ($request->file('diplomas') as $diploma) {
                    $this->salvarArquivo($diploma, $candidato, 'diploma');
                }
            }

            $token = $user->createToken('auth', ['role:candidato', "context:{$candidato->id}"])->plainTextToken;

            return response()->json([
                'token'   => $token,
                'user'    => $user->only('id', 'name', 'email'),
                'role'    => 'candidato',
                'context' => ['id' => $candidato->id, 'nome' => $user->name],
                'is_admin'=> false,
            ], 201);
        });
    }

    private function salvarArquivo($file, Candidato $candidato, string $tipo): void
    {
        $path = $file->store("candidatos/{$candidato->id}/documentos", 'public');

        CandidatoDocumento::create([
            'candidato_id' => $candidato->id,
            'tipo'         => $tipo,
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => (int) round($file->getSize() / 1024),
            'ativo'        => true,
        ]);
    }
}
