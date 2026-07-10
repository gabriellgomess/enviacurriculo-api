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
            $candidato->load(['user:id,name,email,phone,active', 'documentos', 'franquia:id,nome'])
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

    public function pareceres(Request $request, int $id)
    {
        $pareceres = \App\Models\CandidatoParecer::with(['criador:id,name', 'franquia:id,nome'])
            ->where('candidato_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) {
                return [
                    'id'                => $p->id,
                    'parecer'           => $p->texto,
                    'nota'              => $p->nota,
                    'criado_por_nome'   => $p->criador?->name ?? 'Sistema',
                    'franquia_nome'     => $p->franquia?->nome ?? 'Administração',
                    'created_at'        => $p->created_at,
                ];
            });

        return response()->json(['data' => $pareceres]);
    }

    public function storeParecer(Request $request, int $id)
    {
        $validated = $request->validate([
            'texto' => 'required|string|max:5000',
            'nota'  => 'nullable|integer|min:1|max:5',
        ]);

        $parecer = \App\Models\CandidatoParecer::create([
            'candidato_id' => $id,
            'criado_por'   => $request->user()->id,
            'texto'        => $validated['texto'],
            'nota'         => $validated['nota'] ?? null,
        ]);

        return response()->json([
            'message' => 'Parecer registrado.',
            'data'    => $parecer,
        ], 201);
    }

    public function updateParecer(Request $request, int $id)
    {
        $parecer = \App\Models\CandidatoParecer::findOrFail($id);

        $validated = $request->validate([
            'texto' => 'required|string|max:5000',
            'nota'  => 'nullable|integer|min:1|max:5',
        ]);

        $parecer->update([
            'texto' => $validated['texto'],
            'nota'  => $validated['nota'] ?? null,
        ]);

        return response()->json([
            'message' => 'Parecer atualizado com sucesso.',
            'data'    => $parecer,
        ]);
    }

    public function destroyParecer(Request $request, int $id)
    {
        $parecer = \App\Models\CandidatoParecer::findOrFail($id);
        $parecer->delete();

        return response()->json([
            'message' => 'Parecer excluído com sucesso.',
        ]);
    }

    public function vincular(Request $request, Candidato $candidato)
    {
        $request->validate([
            'vagas_ids' => 'required|array',
            'vagas_ids.*' => 'integer|exists:vagas,id',
        ]);

        if (!$candidato->pareceres()->exists()) {
            return response()->json([
                'message' => 'Candidato precisa ter um parecer registrado antes de ser vinculado a uma vaga.',
            ], 422);
        }

        $curriculo = $candidato->documentos()->where('ativo', true)->first()
            ?? $candidato->documentos()->latest()->first();

        $vinculados = [];
        foreach ($request->vagas_ids as $vagaId) {
            $envio = \App\Models\Envio::firstOrCreate(
                ['candidato_id' => $candidato->id, 'vaga_id' => $vagaId],
                ['curriculo_id' => $curriculo?->id, 'status' => 'enviado']
            );
            $vinculados[] = $vagaId;
        }

        return response()->json([
            'message' => 'Candidato vinculado com sucesso.',
            'data'    => $vinculados,
        ]);
    }

    public function vinculacoes(Request $request, Candidato $candidato)
    {
        $envios = \App\Models\Envio::with(['vaga:id,titulo,empresa_id', 'vaga.empresa:id,nome_fantasia,razao_social'])
            ->where('candidato_id', $candidato->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($envio) {
                return [
                    'id' => $envio->id,
                    'vaga_nome' => $envio->vaga?->titulo ?? 'Vaga Desconhecida',
                    'empresa_nome' => $envio->vaga?->empresa?->nome_fantasia ?? $envio->vaga?->empresa?->razao_social ?? 'Empresa Desconhecida',
                    'status' => $envio->status,
                    'created_at' => $envio->created_at,
                ];
            });

        return response()->json(['data' => $envios]);
    }

    public function disc(Request $request, int $id)
    {
        $disc = \App\Models\CandidatoDisc::where('candidato_id', $id)
            ->with('aplicador:id,name')
            ->latest()
            ->first();

        if (!$disc) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'perfil_dominante'  => $disc->perfil_dominante,
            'score_d'           => $disc->score_d,
            'score_i'           => $disc->score_i,
            'score_s'           => $disc->score_s,
            'score_c'           => $disc->score_c,
            'aplicado_por_nome' => $disc->aplicador?->name,
            'created_at'        => $disc->created_at,
        ]]);
    }

    public function storeDisc(Request $request, int $id)
    {
        $validated = $request->validate([
            'perfil_dominante' => 'required|string|in:D,I,S,C',
            'score_d'          => 'required|integer|min:0|max:100',
            'score_i'          => 'required|integer|min:0|max:100',
            'score_s'          => 'required|integer|min:0|max:100',
            'score_c'          => 'required|integer|min:0|max:100',
        ]);

        $disc = \App\Models\CandidatoDisc::create([
            'candidato_id'     => $id,
            'aplicado_por'     => $request->user()->id,
            'perfil_dominante' => $validated['perfil_dominante'],
            'score_d'          => $validated['score_d'],
            'score_i'          => $validated['score_i'],
            'score_s'          => $validated['score_s'],
            'score_c'          => $validated['score_c'],
        ]);

        return response()->json([
            'message' => 'Resultado do teste DISC registrado com sucesso.',
            'data'    => $disc
        ], 201);
    }

    public function destroyVinculo(int $id)
    {
        $envio = \App\Models\Envio::findOrFail($id);
        $envio->delete();

        return response()->json([
            'message' => 'Candidato desvinculado com sucesso.'
        ]);
    }
}
