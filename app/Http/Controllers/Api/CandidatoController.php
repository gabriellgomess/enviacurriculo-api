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

        if ($request->filled('telefone')) {
            $digits = preg_replace('/\D/', '', $request->telefone);
            $query->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE ?", ["%{$digits}%"]);
        }

        if ($request->filled('email')) {
            $e = $request->email;
            $query->whereHas('user', fn($u) => $u->where('email', 'like', "%{$e}%"));
        }

        if ($request->filled('cidade')) {
            $query->where('cidade', 'like', '%' . $request->cidade . '%');
        }

        // Cadastro de origem: 'portal' (auto-cadastro) ou id da franquia
        if ($request->filled('origem')) {
            if ($request->origem === 'portal') {
                $query->whereNull('franquia_id');
            } else {
                $query->where('franquia_id', $request->origem);
            }
        }

        // Período do cadastro
        if ($request->filled('data_de')) {
            $query->whereDate('created_at', '>=', $request->data_de);
        }
        if ($request->filled('data_ate')) {
            $query->whereDate('created_at', '<=', $request->data_ate);
        }

        // Número de vínculos (envios)
        $query->withCount('envios')->with('franquia:id,nome');
        if ($request->filled('vinculos_min')) {
            $query->having('envios_count', '>=', (int) $request->vinculos_min);
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

        $ativo = ($data['status'] ?? 'ativo') === 'ativo';

        $candidato = DB::transaction(function () use ($data, $request, $ativo) {
            $user = User::create([
                'name'     => $data['nome'],
                'email'    => $data['email'] ?? ('cv_' . Str::uuid() . '@banco.local'),
                'password' => Hash::make(Str::random(40)),
                'active'   => $ativo,
            ]);

            // Papel de candidato para o usuário conseguir acessar o painel
            UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'candidato']);

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

            // Contexto de acesso ao painel do candidato
            UserContext::firstOrCreate([
                'user_id'    => $user->id,
                'role'       => 'candidato',
                'context_id' => $candidato->id,
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
            'cargos_interesse'         => 'nullable|array|max:8',
            'cargos_interesse.*'       => 'string|max:100',
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
            'cargo_desejado'           => array_key_exists('cargos_interesse', $validated)
                ? ($validated['cargos_interesse'][0] ?? null)
                : ($validated['cargo_desejado'] ?? $candidato->cargo_desejado),
            'cargos_interesse'         => $validated['cargos_interesse']         ?? $candidato->cargos_interesse,
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
            if (!empty($validated['password'])) {
                $userUpdate['password'] = Hash::make($validated['password']);
                // Definir senha = liberar acesso: garante papel, contexto e usuário ativo
                $userUpdate['active'] = true;
                UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'candidato']);
                UserContext::firstOrCreate([
                    'user_id'    => $user->id,
                    'role'       => 'candidato',
                    'context_id' => $candidato->id,
                ]);
            }
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
        $pareceres = \App\Models\CandidatoParecer::with(['criador:id,name', 'franquia:id,nome', 'empresa:id,razao_social', 'vaga:id,titulo'])
            ->where('candidato_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) {
                return [
                    'id'                => $p->id,
                    'parecer'           => $p->texto,
                    'nota'              => $p->nota,
                    'dados'             => $p->dados,
                    'empresa_id'        => $p->empresa_id,
                    'empresa_nome'      => $p->empresa?->razao_social,
                    'vaga_titulo'       => $p->vaga?->titulo,
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
            'texto'      => 'required|string|max:5000',
            'nota'       => 'nullable|integer|min:1|max:5',
            'empresa_id' => 'nullable|integer|exists:empresas,id',
            'vaga_id'    => 'nullable|integer|exists:vagas,id',
            'dados'      => 'nullable|array',
        ]);

        $parecer = \App\Models\CandidatoParecer::create([
            'candidato_id' => $id,
            'criado_por'   => $request->user()->id,
            'texto'        => $validated['texto'],
            'nota'         => $validated['nota'] ?? null,
            'empresa_id'   => $validated['empresa_id'] ?? null,
            'vaga_id'      => $validated['vaga_id'] ?? null,
            'dados'        => $validated['dados'] ?? null,
        ]);

        app(\App\Services\SincronizacaoAgendamentosParecer::class)->doParecer($parecer);

        return response()->json([
            'message' => 'Parecer registrado.',
            'data'    => $parecer,
        ], 201);
    }

    public function updateParecer(Request $request, int $id)
    {
        $parecer = \App\Models\CandidatoParecer::findOrFail($id);

        $validated = $request->validate([
            'texto'      => 'required|string|max:5000',
            'nota'       => 'nullable|integer|min:1|max:5',
            'empresa_id' => 'nullable|integer|exists:empresas,id',
            'dados'      => 'nullable|array',
        ]);

        $parecer->update(array_merge([
            'texto' => $validated['texto'],
            'nota'  => $validated['nota'] ?? null,
        ], array_key_exists('empresa_id', $validated) ? ['empresa_id' => $validated['empresa_id']] : [],
           array_key_exists('dados', $validated) ? ['dados' => $validated['dados']] : []));

        app(\App\Services\SincronizacaoAgendamentosParecer::class)->doParecer($parecer);

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
                // Encaminhado pela operação (agência), não candidatura espontânea
                ['curriculo_id' => $curriculo?->id, 'status' => 'enviado', 'origem' => 'franquia']
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

    /**
     * GET /admin/candidatos/status
     *
     * Mesma tela de "Status Candidatos" da franquia, mas sem o recorte por
     * franquia_id/vagas — o admin enxerga e altera o vínculo de qualquer
     * candidato com qualquer vaga.
     */
    public function status(Request $request)
    {
        $query = \App\Models\Envio::with([
            'candidato.user:id,name',
            'candidato.franquia:id,nome',
            'vaga:id,titulo,empresa_id,tipo_contrato',
            'vaga.empresa:id,razao_social',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $termo = trim($request->search);
            $query->where(function ($q) use ($termo) {
                $q->whereHas('candidato.user', fn($u) => $u->where('name', 'like', "%{$termo}%"))
                  ->orWhereHas('vaga', fn($v) => $v->where('titulo', 'like', "%{$termo}%"))
                  ->orWhereHas('vaga.empresa', fn($e) => $e->where('razao_social', 'like', "%{$termo}%"));
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $envios  = $query->orderByDesc('created_at')->paginate($perPage);

        $data = $envios->getCollection()->map(fn($e) => [
            'id'                 => $e->id,
            'candidato_id'       => $e->candidato_id,
            'candidato_nome'     => $e->candidato?->user?->name ?? '—',
            'vaga_id'            => $e->vaga_id,
            'vaga_nome'          => $e->vaga?->titulo ?? '—',
            'vaga_salario'       => $e->vaga?->salario_min ?? '—',
            'empresa_nome'       => $e->vaga?->empresa?->razao_social ?? '—',
            'franquia'           => $e->candidato?->franquia?->nome ?? '—',
            'status'             => $e->status,
            'observacao'         => $e->observacao,
            'salario_aprovado'   => $e->salario_aprovado,
            'tipo_contrato'      => $e->tipo_contrato,
            'vaga_tipo_contrato' => $e->vaga?->tipo_contrato,
            'data_admissao'      => $e->data_admissao?->toDateString(),
            'data_saida'         => $e->data_saida?->toDateString(),
            'created_at'         => $e->created_at,
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $envios->total(),
                'per_page'     => $envios->perPage(),
                'current_page' => $envios->currentPage(),
                'last_page'    => $envios->lastPage(),
            ],
        ]);
    }

    /**
     * PATCH /admin/candidatos/{candidatoId}/vagas/{vagaId}/status
     *
     * Mesmo comportamento de FranquiaCandidatoController::updateStatus, sem o
     * recorte por franquia — o admin pode alterar qualquer vínculo.
     */
    public function updateStatus(Request $request, int $candidatoId, int $vagaId)
    {
        $data = $request->validate([
            'status'           => 'required|in:enviado,visualizado,em_processo,pendente,aprovado,reprovado,desistiu,reposicao',
            'observacao'       => 'nullable|string',
            'salario_aprovado' => 'nullable|numeric|min:0',
            'tipo_contrato'    => 'nullable|string|max:50',
            'data_admissao'    => 'nullable|date',
            'data_saida'       => 'nullable|date',
        ]);

        $envio = \App\Models\Envio::where('candidato_id', $candidatoId)->where('vaga_id', $vagaId)->first();

        if (!$envio) {
            return response()->json([
                'message' => 'Vínculo não encontrado.',
            ], 404);
        }

        // `pendente` e `enviado` são a mesma etapa; o banco guarda `enviado`.
        $status = $data['status'] === 'pendente' ? 'enviado' : $data['status'];

        $envio->fill([
            'status' => $status,
            // Mesmo processo seletivo: reflete no painel da empresa
            'status_empresa' => \App\Models\Envio::statusEmpresaPara($status),
        ]);
        foreach (['observacao', 'salario_aprovado', 'tipo_contrato', 'data_admissao', 'data_saida'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $envio->{$campo} = $data[$campo];
            }
        }
        $envio->save();

        return response()->json(['message' => 'Status atualizado.', 'status' => $envio->status]);
    }
}
