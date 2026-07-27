<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Empresa;
use App\Models\EmpresaCurriculo;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use App\Services\GeocodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmpresaBancoCurriculoController extends Controller
{
    use HasTokenContext;

    public function __construct(private readonly GeocodeService $geocoder) {}

    /**
     * Calcula as coordenadas do currículo para o Mapa de Candidatos.
     * Best-effort: falha de geocodificação não impede salvar o currículo.
     */
    private function coordenadas(array $dados): array
    {
        if (empty($dados['cidade']) && empty($dados['bairro'])) {
            return [];
        }

        try {
            $coords = $this->geocoder->geocode(
                $dados['rua']    ?? null,
                $dados['numero'] ?? null,
                $dados['bairro'] ?? null,
                $dados['cidade'] ?? null,
                $dados['estado'] ?? null,
            );
        } catch (\Throwable) {
            return [];
        }

        return $coords ? ['latitude' => $coords['latitude'], 'longitude' => $coords['longitude']] : [];
    }

    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        // Ingere no banco interno os candidatos que aplicaram em vagas da empresa
        $this->ingerirEnvios($empresaId);

        $curriculos = EmpresaCurriculo::where('empresa_id', $empresaId)
            ->when($request->filled('q'), function ($query) use ($request) {
                $s = $request->q;
                $query->where(fn($q) => $q->where('nome', 'like', "%{$s}%")
                    ->orWhere('cargo_desejado', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%"));
            })
            ->when($request->filled('cidade'), fn($q) => $q->where('cidade', 'like', "%{$request->cidade}%"))
            ->when($request->filled('estado'), fn($q) => $q->where('estado', $request->estado))
            ->when($request->filled('origem'), fn($q) => $q->where('origem', $request->origem))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($curriculos->items())->map(fn($c) => $this->payload($c)),
            'meta' => ['current_page' => $curriculos->currentPage(), 'last_page' => $curriculos->lastPage(),
                       'per_page' => $curriculos->perPage(), 'total' => $curriculos->total()],
        ]);
    }

    public function store(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        // O front envia "nome_completo"; o contrato original previa "nome" — aceita ambos
        if (!$request->filled('nome') && $request->filled('nome_completo')) {
            $request->merge(['nome' => $request->nome_completo]);
        }

        $data = $request->validate([
            'nome'                     => 'required|string|max:255',
            'email'                    => 'nullable|email|max:255',
            'telefone'                 => 'nullable|string|max:20',
            'cpf'                      => 'nullable|string|max:14',
            'cargo_desejado'           => 'nullable|string|max:255',
            'cargos_interesse'         => 'nullable|array',
            'cargos_interesse.*'       => 'string|max:100',
            'experiencia_profissional' => 'nullable|string',
            'educacao'                 => 'nullable|string',
            'habilidades'              => 'nullable|string',
            'cidade'                   => 'nullable|string|max:100',
            'estado'                   => 'nullable|string|size:2',
            'bairro'                   => 'nullable|string|max:100',
            'cep'                      => 'nullable|string|max:9',
            'rua'                      => 'nullable|string|max:255',
            'numero'                   => 'nullable|string|max:20',
            'complemento'              => 'nullable|string|max:100',
            'tipo_cnh'                 => 'nullable|string|max:10',
            'informacoes_pessoais'     => 'nullable|string',
            'idiomas'                  => 'nullable|string|max:500',
            'informacoes_adicionais'   => 'nullable|string',
            'status'                   => 'nullable|in:ativo,inativo',
            'origem'                   => 'nullable|in:manual,copia_base',
            'arquivo'                  => 'nullable|file|max:10240|mimes:pdf,doc,docx',
            'arquivo_cnh'              => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'arquivo_ctps'             => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'arquivos_diploma'         => 'nullable|array',
            'arquivos_diploma.*'       => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $dir   = "empresas/{$empresaId}/banco-curriculos";
        $extra = [
            'empresa_id' => $empresaId,
            'origem'     => $data['origem'] ?? 'manual',
            'active'     => ($data['status'] ?? 'ativo') === 'ativo',
        ];

        if ($request->hasFile('arquivo')) {
            $arquivo = $request->file('arquivo');
            $extra['arquivo_path'] = $arquivo->store($dir, 'public');
            $extra['arquivo_nome'] = $arquivo->getClientOriginalName();
        }

        foreach (['arquivo_cnh' => 'cnh', 'arquivo_ctps' => 'ctps'] as $campo => $slug) {
            if ($request->hasFile($campo)) {
                $f = $request->file($campo);
                $extra["arquivo_{$slug}_path"] = $f->store($dir, 'public');
                $extra["arquivo_{$slug}_nome"] = $f->getClientOriginalName();
            }
        }

        if ($request->hasFile('arquivos_diploma')) {
            $extra['diplomas'] = collect($request->file('arquivos_diploma'))
                ->map(fn($f) => ['path' => $f->store($dir, 'public'), 'nome' => $f->getClientOriginalName()])
                ->all();
        }

        $coords = $this->coordenadas($data);

        // Currículo novo também entra no BANCO OFICIAL da EnviaCurrículo.
        // Se o candidato já existir lá, apenas vincula (não duplica).
        $candidatoId = $this->publicarNoBancoOficial($data, $coords);

        // (empresa_id, candidato_id) é único: evita o 500 do índice quando a
        // empresa insiste em cadastrar alguém que já está no banco dela.
        if ($candidatoId) {
            $jaExiste = EmpresaCurriculo::where('empresa_id', $empresaId)
                ->where('candidato_id', $candidatoId)
                ->exists();

            if ($jaExiste) {
                return response()->json([
                    'message' => 'Este candidato já está no seu banco de currículos.',
                    'errors'  => ['nome' => ['Este candidato já está no seu banco de currículos.']],
                ], 422);
            }
        }

        $curriculo = EmpresaCurriculo::create([
            ...collect($data)->except(['arquivo', 'arquivo_cnh', 'arquivo_ctps', 'arquivos_diploma', 'origem', 'status'])->all(),
            ...$extra,
            ...$coords,
            'candidato_id' => $candidatoId,
        ]);

        return response()->json(['data' => $this->payload($curriculo)], 201);
    }

    public function copiaBase(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $empresa = Empresa::find($empresaId);
        if ($empresa && $empresa->plano === 'basico') {
            return response()->json([
                'message'    => 'Cópia do banco da franquia disponível apenas no Plano Padrão/Premium.',
                'upgrade_to' => 'padrao',
            ], 402);
        }

        // Aceita copiar por documento (fluxo antigo) ou direto pelo candidato
        // encontrado na checagem de duplicidade no banco oficial.
        $data = $request->validate([
            'curriculo_id' => 'required_without:candidato_id|integer|exists:candidato_documentos,id',
            'candidato_id' => 'required_without:curriculo_id|integer|exists:candidatos,id',
        ]);

        if (!empty($data['curriculo_id'])) {
            $doc       = CandidatoDocumento::with('candidato.user')->findOrFail($data['curriculo_id']);
            $candidato = $doc->candidato;
        } else {
            $doc       = null;
            $candidato = Candidato::with('user')->findOrFail($data['candidato_id']);
            // Sem documento informado, leva o currículo ativo do candidato
            $doc = $candidato->documentos()->where('ativo', true)->first()
                ?? $candidato->documentos()->latest()->first();
        }

        $curriculo = EmpresaCurriculo::updateOrCreate(
            ['empresa_id' => $empresaId, 'candidato_id' => $candidato->id],
            [
                'nome'                     => $candidato->user?->name ?? 'Candidato',
                'email'                    => $candidato->user?->email,
                'telefone'                 => $candidato->telefone,
                'cpf'                      => $candidato->cpf,
                'cargo_desejado'           => $candidato->cargo_desejado,
                'cargos_interesse'         => $candidato->cargos_interesse,
                'experiencia_profissional' => $candidato->experiencia_profissional,
                'educacao'                 => $candidato->educacao,
                'habilidades'              => $candidato->habilidades,
                'cidade'                   => $candidato->cidade,
                'estado'                   => $candidato->estado,
                'bairro'                   => $candidato->bairro,
                'cep'                      => $candidato->cep,
                'rua'                      => $candidato->rua,
                'numero'                   => $candidato->numero,
                'latitude'                 => $candidato->latitude,
                'longitude'                => $candidato->longitude,
                'origem'                   => 'copia_base',
                'arquivo_path'             => $doc?->arquivo_path,
                'arquivo_nome'             => $doc?->arquivo_nome,
            ],
        );

        return response()->json(['data' => $this->payload($curriculo)], 201);
    }

    public function update(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        if (!$request->filled('nome') && $request->filled('nome_completo')) {
            $request->merge(['nome' => $request->nome_completo]);
        }

        $data = $request->validate([
            'nome'                     => 'required|string|max:255',
            'email'                    => 'nullable|email|max:255',
            'telefone'                 => 'nullable|string|max:20',
            'cpf'                      => 'nullable|string|max:14',
            'cargo_desejado'           => 'nullable|string|max:255',
            'cargos_interesse'         => 'nullable|array',
            'cargos_interesse.*'       => 'string|max:100',
            'experiencia_profissional' => 'nullable|string',
            'educacao'                 => 'nullable|string',
            'habilidades'              => 'nullable|string',
            'cidade'                   => 'nullable|string|max:100',
            'estado'                   => 'nullable|string|size:2',
            'bairro'                   => 'nullable|string|max:100',
        ]);

        $curriculo = EmpresaCurriculo::where('empresa_id', $empresaId)->findOrFail($id);

        // Reposiciona no mapa se o endereço mudou
        $enderecoMudou = collect(['cidade', 'estado', 'bairro'])
            ->contains(fn($campo) => array_key_exists($campo, $data) && $data[$campo] !== $curriculo->$campo);

        $curriculo->update([
            ...$data,
            ...($enderecoMudou ? $this->coordenadas([...$curriculo->only(['rua', 'numero']), ...$data]) : []),
        ]);

        // Complementa o cadastro oficial apenas onde ele está vazio
        $this->enriquecerBancoOficial($curriculo, $data);

        return response()->json(['message' => 'Currículo atualizado.']);
    }

    public function destroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $curriculo = EmpresaCurriculo::where('empresa_id', $empresaId)->findOrFail($id);

        if ($curriculo->origem === 'manual') {
            $paths = array_filter([
                $curriculo->arquivo_path,
                $curriculo->arquivo_cnh_path,
                $curriculo->arquivo_ctps_path,
                ...collect($curriculo->diplomas ?? [])->pluck('path')->all(),
            ]);
            if ($paths) {
                Storage::disk('public')->delete($paths);
            }
        }

        $curriculo->delete();

        return response()->noContent();
    }

    public function updateEtapa(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate(['kanban_etapa_id' => 'required|integer|exists:kanban_etapas,id']);

        $curriculo = EmpresaCurriculo::where('empresa_id', $empresaId)->findOrFail($id);
        $curriculo->update($data);

        return response()->json([
            'message'   => 'Etapa atualizada.',
            'curriculo' => ['id' => $curriculo->id, 'kanban_etapa_id' => $curriculo->kanban_etapa_id],
        ]);
    }

    /**
     * Enriquece o candidato no BANCO OFICIAL com dados que a empresa preencheu,
     * SOMENTE nos campos que estão vazios no registro oficial.
     *
     * Decisão de produto: a base oficial é compartilhada (outras empresas,
     * franquias e o próprio candidato, que edita o perfil dele). Por isso a
     * empresa complementa lacunas, mas nunca sobrescreve dado já existente.
     */
    private function enriquecerBancoOficial(EmpresaCurriculo $curriculo, array $data): void
    {
        if (!$curriculo->candidato_id) {
            return;
        }

        $candidato = Candidato::find($curriculo->candidato_id);
        if (!$candidato) {
            return;
        }

        // origem no currículo da empresa → campo no cadastro oficial
        $mapa = [
            'telefone'                 => 'telefone',
            'cpf'                      => 'cpf',
            'cep'                      => 'cep',
            'rua'                      => 'rua',
            'numero'                   => 'numero',
            'complemento'              => 'complemento',
            'bairro'                   => 'bairro',
            'cidade'                   => 'cidade',
            'estado'                   => 'estado',
            'tipo_cnh'                 => 'tipo_cnh',
            'cargo_desejado'           => 'cargo_desejado',
            'cargos_interesse'         => 'cargos_interesse',
            'informacoes_pessoais'     => 'apresentacao',
            'experiencia_profissional' => 'experiencia_profissional',
            'educacao'                 => 'educacao',
            'habilidades'              => 'habilidades',
            'idiomas'                  => 'idiomas',
            'informacoes_adicionais'   => 'informacoes_adicionais',
        ];

        $preencher = [];

        foreach ($mapa as $campoEmpresa => $campoOficial) {
            $novo = $data[$campoEmpresa] ?? null;

            // Nada a acrescentar
            if ($novo === null || $novo === '' || $novo === []) {
                continue;
            }

            // Já preenchido no oficial → preserva (nunca sobrescreve)
            $atual = $candidato->$campoOficial;
            if ($atual !== null && $atual !== '' && $atual !== []) {
                continue;
            }

            $preencher[$campoOficial] = $novo;
        }

        if ($preencher) {
            $candidato->update($preencher);
        }
    }

    /**
     * Publica no BANCO OFICIAL (candidatos) um currículo cadastrado pela
     * empresa. Se já existir candidato com o mesmo e-mail/CPF/telefone, apenas
     * devolve o id existente — nunca duplica nem sobrescreve o registro oficial.
     *
     * Retorna o candidato_id para vincular ao currículo da empresa.
     */
    private function publicarNoBancoOficial(array $data, array $coords = []): ?int
    {
        $existente = Candidato::where(function ($q) use ($data) {
            if (!empty($data['cpf'])) {
                $q->orWhere('cpf', $data['cpf']);
            }
            if (!empty($data['telefone'])) {
                $digits = preg_replace('/\D/', '', $data['telefone']);
                if ($digits) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),'-',''),' ','') = ?",
                        [$digits]
                    );
                }
            }
            if (!empty($data['email'])) {
                $email = $data['email'];
                $q->orWhereHas('user', fn($u) => $u->where('email', $email));
            }
        })->first();

        if ($existente) {
            return $existente->id;
        }

        // E-mail é opcional no banco de currículos; sem ele, gera um interno
        // (mesmo padrão do banco de currículos do admin/franquia).
        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            return null; // e-mail já usado por outro tipo de usuário — não cria
        }

        return DB::transaction(function () use ($data, $coords) {
            $user = User::create([
                'name'     => $data['nome'],
                'email'    => $data['email'] ?? ('cv_' . Str::uuid() . '@banco.local'),
                'phone'    => $data['telefone'] ?? null,
                'password' => Hash::make(Str::random(40)),
                'active'   => ($data['status'] ?? 'ativo') === 'ativo',
            ]);

            UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'candidato']);

            $candidato = Candidato::create([
                'user_id'                  => $user->id,
                'franquia_id'              => null,
                'telefone'                 => $data['telefone'] ?? null,
                'cpf'                      => $data['cpf'] ?? null,
                'cep'                      => $data['cep'] ?? null,
                'rua'                      => $data['rua'] ?? null,
                'numero'                   => $data['numero'] ?? null,
                'complemento'              => $data['complemento'] ?? null,
                'bairro'                   => $data['bairro'] ?? null,
                'cidade'                   => $data['cidade'] ?? null,
                'estado'                   => $data['estado'] ?? null,
                'tipo_cnh'                 => $data['tipo_cnh'] ?? null,
                'cargo_desejado'           => $data['cargo_desejado'] ?? null,
                'cargos_interesse'         => $data['cargos_interesse'] ?? null,
                'apresentacao'             => $data['informacoes_pessoais'] ?? null,
                'experiencia_profissional' => $data['experiencia_profissional'] ?? null,
                'educacao'                 => $data['educacao'] ?? null,
                'habilidades'              => $data['habilidades'] ?? null,
                'idiomas'                  => $data['idiomas'] ?? null,
                'informacoes_adicionais'   => $data['informacoes_adicionais'] ?? null,
                'active'                   => ($data['status'] ?? 'ativo') === 'ativo',
                ...$coords,
            ]);

            UserContext::firstOrCreate([
                'user_id'    => $user->id,
                'role'       => 'candidato',
                'context_id' => $candidato->id,
            ]);

            return $candidato->id;
        });
    }

    /**
     * Verifica se o candidato já existe no BANCO OFICIAL da EnviaCurrículo
     * (tabela candidatos) antes da empresa cadastrar um currículo novo.
     *
     * Retorna também se a empresa já possui esse candidato no próprio banco,
     * para a interface não oferecer uma cópia duplicada.
     */
    public function duplicata(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $request->validate([
            'nome'     => 'nullable|string|max:255',
            'email'    => 'nullable|email',
            'telefone' => 'nullable|string|max:20',
            'cpf'      => 'nullable|string|max:14',
        ]);

        if (!$request->filled('email') && !$request->filled('cpf')
            && !$request->filled('nome') && !$request->filled('telefone')) {
            return response()->json(['duplicado' => false]);
        }

        $oficial = Candidato::with('user:id,name,email')
            ->where(function ($q) use ($request) {
                if ($request->filled('cpf')) {
                    $q->orWhere('cpf', $request->cpf);
                }
                if ($request->filled('telefone')) {
                    $digits = preg_replace('/\D/', '', $request->telefone);
                    if ($digits) {
                        $q->orWhereRaw(
                            "REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),'-',''),' ','') = ?",
                            [$digits]
                        );
                    }
                }
                if ($request->filled('email')) {
                    $email = $request->email;
                    $q->orWhereHas('user', fn($u) => $u->where('email', $email));
                }
                if ($request->filled('nome')) {
                    $nome = $request->nome;
                    $q->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$nome}%"));
                }
            })
            ->first();

        if (!$oficial) {
            return response()->json(['duplicado' => false]);
        }

        // A empresa já tem esse candidato no banco dela?
        $jaNoBanco = EmpresaCurriculo::where('empresa_id', $empresaId)
            ->where('candidato_id', $oficial->id)
            ->first();

        return response()->json([
            'duplicado'    => true,
            'candidato'    => [
                'id'             => $oficial->id,
                'nome'           => $oficial->user?->name,
                'email'          => $oficial->user?->email,
                'telefone'       => $oficial->telefone,
                'cidade'         => $oficial->cidade,
                'estado'         => $oficial->estado,
                'cargo_desejado' => $oficial->cargo_desejado,
            ],
            'ja_no_banco'  => (bool) $jaNoBanco,
            'curriculo_id' => $jaNoBanco?->id,
        ]);
    }

    public function download(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $curriculo = EmpresaCurriculo::where('empresa_id', $empresaId)->findOrFail($id);

        if (!$curriculo->arquivo_path || !Storage::disk('public')->exists($curriculo->arquivo_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('public')->download($curriculo->arquivo_path, $curriculo->arquivo_nome);
    }

    /* ─── Helpers ────────────────────────────────────────────────────── */

    /**
     * Garante que todo candidato com envio para vagas da empresa
     * tenha registro no banco de currículos (origem plataforma).
     */
    private function ingerirEnvios(int $empresaId): void
    {
        try {
            DB::statement("
                INSERT IGNORE INTO empresa_curriculos
                    (empresa_id, candidato_id, nome, email, telefone, cpf, cargo_desejado,
                     cidade, estado, origem, arquivo_path, arquivo_nome, created_at, updated_at)
                SELECT ?, c.id, u.name, u.email, c.telefone, c.cpf, c.cargo_desejado,
                       c.cidade, c.estado, 'plataforma', cd.arquivo_path, cd.arquivo_nome, NOW(), NOW()
                FROM (
                    SELECT MAX(e.id) AS envio_id, e.candidato_id
                    FROM envios e
                    JOIN vagas v ON v.id = e.vaga_id AND v.empresa_id = ?
                    GROUP BY e.candidato_id
                ) ult
                JOIN envios e              ON e.id = ult.envio_id
                JOIN candidatos c          ON c.id = ult.candidato_id
                JOIN users u               ON u.id = c.user_id
                LEFT JOIN candidato_documentos cd ON cd.id = e.curriculo_id
            ", [$empresaId, $empresaId]);
        } catch (\Throwable) {
            // ingestão é best-effort; a listagem segue com o que existir
        }
    }

    private function payload(EmpresaCurriculo $c): array
    {
        return [
            'id'                       => $c->id,
            'candidato_id'             => $c->candidato_id,
            'kanban_etapa_id'          => $c->kanban_etapa_id,
            'nome'                     => $c->nome,
            'nome_completo'            => $c->nome, // alias usado pelo front
            'email'                    => $c->email,
            'telefone'                 => $c->telefone,
            'cargo_desejado'           => $c->cargo_desejado,
            'cargos_interesse'         => $c->cargos_interesse ?? [],
            'experiencia_profissional' => $c->experiencia_profissional,
            'educacao'                 => $c->educacao,
            'habilidades'              => $c->habilidades,
            'cidade'                   => $c->cidade,
            'estado'                   => $c->estado,
            'bairro'                   => $c->bairro,
            'cep'                      => $c->cep,
            'rua'                      => $c->rua,
            'numero'                   => $c->numero,
            'complemento'              => $c->complemento,
            'tipo_cnh'                 => $c->tipo_cnh,
            'informacoes_pessoais'     => $c->informacoes_pessoais,
            'idiomas'                  => $c->idiomas,
            'informacoes_adicionais'   => $c->informacoes_adicionais,
            'status'                   => $c->active ? 'ativo' : 'inativo',
            'origem'                   => $c->origem,
            'arquivo_nome'             => $c->arquivo_nome,
            'arquivo_cnh_nome'         => $c->arquivo_cnh_nome,
            'arquivo_cnh_url'          => $c->arquivo_cnh_path ? Storage::disk('public')->url($c->arquivo_cnh_path) : null,
            'arquivo_ctps_nome'        => $c->arquivo_ctps_nome,
            'arquivo_ctps_url'         => $c->arquivo_ctps_path ? Storage::disk('public')->url($c->arquivo_ctps_path) : null,
            'diplomas'                 => collect($c->diplomas ?? [])->map(fn($d) => [
                'nome' => $d['nome'] ?? null,
                'url'  => isset($d['path']) ? Storage::disk('public')->url($d['path']) : null,
            ])->all(),
            'created_at'               => $c->created_at,
        ];
    }
}
