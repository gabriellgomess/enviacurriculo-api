<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\CandidatoParecer;
use App\Models\Empresa;
use App\Models\Envio;
use App\Models\EnvioParecer;
use App\Models\User;
use App\Models\Vaga;
use App\Services\GeocodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FranquiaCandidatoController extends Controller
{
    use HasTokenContext;

    public function __construct(private readonly GeocodeService $geocoder) {}

    private function vagaIds(int $franquiaId): \Illuminate\Support\Collection
    {
        return Vaga::where('franquia_id', $franquiaId)->pluck('id');
    }

    // GET /franquia/pareceres
    // Todos os pareceres (empresa) das vagas das empresas sob esta franquia —
    // pendentes de validacao e ja enviados/decididos.
    public function pareceresEmpresas(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresaIds = Empresa::where('franquia_id', $franquiaId)->pluck('id');

        $query = EnvioParecer::whereHas('envio.vaga', fn($q) => $q->whereIn('empresa_id', $empresaIds))
            ->with([
                'envio.vaga:id,titulo,empresa_id',
                'envio.vaga.empresa:id,razao_social,nome_fantasia',
                'envio.candidato.user:id,name',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $pareceres = $query->get()->map(fn($p) => [
            'id'         => $p->id,
            'texto'      => $p->texto,
            'autor'      => $p->autor,
            'status'     => $p->status,
            'motivo_validacao' => $p->motivo_validacao,
            'candidato'  => $p->envio?->candidato?->user?->name,
            'vaga'       => $p->envio?->vaga?->titulo,
            'empresa'    => $p->envio?->vaga?->empresa?->razao_social
                            ?? $p->envio?->vaga?->empresa?->nome_fantasia,
            'created_at' => $p->created_at,
        ]);

        return response()->json(['data' => $pareceres]);
    }

    // PATCH /franquia/pareceres/{id}/validar
    public function validarParecer(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $empresaIds = Empresa::where('franquia_id', $franquiaId)->pluck('id');

        $data = $request->validate([
            'acao'   => 'required|in:validado,rejeitado',
            'motivo' => 'nullable|string|max:1000',
        ]);

        $parecer = EnvioParecer::whereHas('envio.vaga', fn($q) => $q->whereIn('empresa_id', $empresaIds))
            ->findOrFail($id);

        $parecer->update([
            'status'           => $data['acao'],
            'motivo_validacao' => $data['motivo'] ?? null,
        ]);

        return response()->json(['message' => 'Parecer ' . $data['acao'] . '.', 'data' => ['id' => $parecer->id, 'status' => $parecer->status]]);
    }

    /**
     * Envios da franquia: os que ela registrou (`franquia_id`) mais os das
     * vagas dela. Usado em todas as telas de historico.
     */
    private function escopoEnvios($query, int $franquiaId, \Illuminate\Support\Collection $vagaIds)
    {
        return $query->where(function ($q) use ($franquiaId, $vagaIds) {
            $q->where('franquia_id', $franquiaId)
              ->orWhereIn('vaga_id', $vagaIds);
        });
    }

    /**
     * URL pública do arquivo, ou null quando o arquivo não existe no storage.
     *
     * A checagem importa: 318 documentos vieram da migração sem o arquivo
     * correspondente. Sem ela, o botão ficaria habilitado e levaria a um 404.
     */
    private function urlArquivo(?string $caminho): ?string
    {
        if (empty($caminho) || !Storage::disk('public')->exists($caminho)) {
            return null;
        }

        return Storage::disk('public')->url($caminho);
    }

    /**
     * Candidatos visiveis para a franquia: acervo 100% compartilhado entre todas as franquias (Opção A).
     */
    private function candidatosVisiveisQuery(int $franquiaId, \Illuminate\Support\Collection $vagaIds)
    {
        return Candidato::query();
    }

    // POST /franquia/candidatos  (cadastra novo curriculo no banco da franquia)
    public function store(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

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
            // arquivos
            'arquivo'                  => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'arquivo_cnh'              => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'arquivo_ctps'             => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'arquivos_diploma'         => 'nullable|array',
            'arquivos_diploma.*'       => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Já existe um usuário com este e-mail.'], 422);
        }

        $candidato = DB::transaction(function () use ($data, $franquiaId, $request) {
            // CV de banco nao tem login: User e apenas o portador de nome/email.
            $user = User::create([
                'name'     => $data['nome'],
                'email'    => $data['email'] ?? ('cv_' . Str::uuid() . '@banco.local'),
                'password' => Hash::make(Str::random(40)),
                'active'   => false,
            ]);

            $cargos = $data['cargos_interesse'] ?? null;

            $candidato = Candidato::create([
                'user_id'                  => $user->id,
                'franquia_id'              => $franquiaId,
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
                // cargo_desejado mantido para compatibilidade (1o cargo de interesse)
                'cargo_desejado'           => $cargos ? ($cargos[0] ?? null) : null,
                'cargos_interesse'         => $cargos,
                // a coluna real e 'apresentacao'; o form envia 'informacoes_pessoais'
                'apresentacao'             => $data['informacoes_pessoais'] ?? null,
                'experiencia_profissional' => $data['experiencia_profissional'] ?? null,
                'educacao'                 => $data['educacao'] ?? null,
                'habilidades'              => $data['habilidades'] ?? null,
                'idiomas'                  => $data['idiomas'] ?? null,
                'informacoes_adicionais'   => $data['informacoes_adicionais'] ?? null,
            ]);

            $this->processarUploads($candidato, $request);

            return $candidato;
        });

        if ($candidato->cidade) {
            $coords = $this->geocoder->geocode(
                $candidato->rua, $candidato->numero, $candidato->bairro, $candidato->cidade, $candidato->estado
            );
            if ($coords) {
                $candidato->update($coords);
            }
        }

        return response()->json([
            'message' => 'Currículo inserido com sucesso.',
            'data'    => ['id' => $candidato->id, 'nome' => $data['nome']],
        ], 201);
    }

    /**
     * Salva os arquivos enviados (curriculo/cnh/ctps/diplomas) como
     * CandidatoDocumento no disco public (unico volume persistido na VPS).
     */
    private function processarUploads(Candidato $candidato, Request $request): void
    {
        $simples = ['arquivo' => 'curriculo', 'arquivo_cnh' => 'cnh', 'arquivo_ctps' => 'ctps'];

        foreach ($simples as $campo => $tipo) {
            if ($request->hasFile($campo)) {
                $this->salvarDocumento($candidato, $request->file($campo), $tipo);
            }
        }

        if ($request->hasFile('arquivos_diploma')) {
            foreach ($request->file('arquivos_diploma') as $file) {
                $this->salvarDocumento($candidato, $file, 'diploma');
            }
        }
    }

    private function salvarDocumento(Candidato $candidato, $file, string $tipo): void
    {
        $path = $file->store("candidatos/{$candidato->id}", 'public');

        CandidatoDocumento::create([
            'candidato_id' => $candidato->id,
            'tipo'         => $tipo,
            'arquivo_path' => $path,
            'arquivo_nome' => $file->getClientOriginalName(),
            'tamanho_kb'   => (int) round($file->getSize() / 1024),
            'ativo'        => true,
        ]);
    }

    // GET /franquia/candidatos
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $query = $this->candidatosVisiveisQuery($franquiaId, $vagaIds)
            ->with(['user:id,name,email', 'franquia:id,nome'])
            ->withExists('pareceres as tem_parecer')
            ->where('active', true);

        // Busca livre — nome, e-mail, cargo, cidade e CPF. Antes o campo da
        // tela filtrava no navegador, sobre os 20 registros carregados: com
        // 12 mil candidatos, procurar por nome quase nunca achava ninguém.
        if ($request->filled('search')) {
            $termo  = trim($request->search);
            $digitos = preg_replace('/\D/', '', $termo);

            $query->where(function ($q) use ($termo, $digitos) {
                $q->where('cargo_desejado', 'like', "%{$termo}%")
                  ->orWhere('cidade', 'like', "%{$termo}%")
                  ->orWhereHas('user', function ($u) use ($termo) {
                      $u->where('name', 'like', "%{$termo}%")
                        ->orWhere('email', 'like', "%{$termo}%");
                  });

                if ($digitos !== '') {
                    $q->orWhere('cpf', 'like', "%{$digitos}%");
                }
            });
        }

        // Só quem já tem parecer — pré-requisito para vincular a uma vaga.
        // O seletor de "Vincular Candidato" usa este filtro: antes ele baixava
        // os 100 mais recentes e descartava no navegador quem não tinha parecer,
        // então a franquia só alcançava os cadastros novos.
        if ($request->boolean('com_parecer')) {
            $query->whereHas('pareceres');
        }

        if ($request->filled('cargo')) {
            $query->where('cargo_desejado', 'like', '%' . $request->cargo . '%');
        }
        if ($request->filled('cidade')) {
            $query->where('cidade', 'like', '%' . $request->cidade . '%');
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('telefone')) {
            $digits = preg_replace('/\D/', '', $request->telefone);
            $query->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(candidatos.telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE ?", ["%{$digits}%"]);
        }
        if ($request->filled('email')) {
            $e = $request->email;
            $query->whereHas('user', fn($u) => $u->where('email', 'like', "%{$e}%"));
        }
        // Cadastro de origem: 'portal' (auto-cadastro) ou id da franquia
        if ($request->filled('origem')) {
            if ($request->origem === 'portal') {
                $query->whereNull('candidatos.franquia_id');
            } else {
                $query->where('candidatos.franquia_id', $request->origem);
            }
        }
        // Período do cadastro
        if ($request->filled('data_de')) {
            $query->whereDate('candidatos.created_at', '>=', $request->data_de);
        }
        if ($request->filled('data_ate')) {
            $query->whereDate('candidatos.created_at', '<=', $request->data_ate);
        }
        // Número de vínculos (envios)
        $query->withCount('envios');
        if ($request->filled('vinculos_min')) {
            $query->having('envios_count', '>=', (int) $request->vinculos_min);
        }

        $perPage     = min((int) $request->query('per_page', 20), 100);
        $candidatos  = $query->orderByDesc('created_at')->paginate($perPage);

        $items = $candidatos->getCollection()->map(fn($c) => [
            'id'               => $c->id,
            'nome'             => $c->user?->name,
            'email'            => $c->user?->email,
            'telefone'         => $c->telefone,
            'status'           => $c->active ? 'ativo' : 'inativo',
            'cpf'              => $c->cpf,
            'cargo_desejado'   => $c->cargo_desejado,
            'cep'              => $c->cep,
            'rua'              => $c->rua,
            'numero'           => $c->numero,
            'bairro'           => $c->bairro,
            'cidade'           => $c->cidade,
            'estado'           => $c->estado,
            'disponibilidade'  => $c->disponibilidade,
            'franquia_responsavel' => $c->franquia?->nome,
            'curriculo_ativo'  => null, // carregado on-demand no show
            'ultimo_envio'     => $this->escopoEnvios($c->envios(), $franquiaId, $vagaIds)->latest()->value('created_at'),
            'tem_parecer'      => (bool) $c->tem_parecer,
            'created_at'       => $c->created_at,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $candidatos->total(),
                'per_page'     => $candidatos->perPage(),
                'current_page' => $candidatos->currentPage(),
                'last_page'    => $candidatos->lastPage(),
            ],
        ]);
    }

    // GET /franquia/candidatos/status  (kanban)
    public function status(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $query = $this->escopoEnvios(
            Envio::with(['candidato.user:id,name', 'candidato.franquia:id,nome', 'vaga:id,titulo,empresa_id,tipo_contrato', 'vaga.empresa:id,razao_social']),
            $franquiaId,
            $vagaIds
        );

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Busca no servidor por candidato, vaga ou empresa
        if ($request->filled('search')) {
            $termo = trim($request->search);
            $query->where(function ($q) use ($termo) {
                $q->whereHas('candidato.user', fn($u) => $u->where('name', 'like', "%{$termo}%"))
                  ->orWhereHas('vaga', fn($v) => $v->where('titulo', 'like', "%{$termo}%"))
                  ->orWhereHas('vaga.empresa', fn($e) => $e->where('razao_social', 'like', "%{$termo}%"));
            });
        }

        if ($request->boolean('list')) {
            // Paginado: a Unidade Matriz é dona de todas as vagas migradas, e
            // sem paginar esta rota carregava os 19 mil envios de uma vez.
            $perPage = min((int) $request->query('per_page', 20), 100);
            $envios  = $query->orderByDesc('created_at')->paginate($perPage);

            $data = $envios->getCollection()->map(fn($e) => [
                'id'               => $e->id,
                'candidato_id'     => $e->candidato_id,
                'candidato_nome'   => $e->candidato?->user?->name ?? '—',
                'vaga_id'          => $e->vaga_id,
                'vaga_nome'        => $e->vaga?->titulo ?? '—',
                'vaga_salario'     => $e->vaga?->salario_min ?? '—',
                'empresa_nome'     => $e->vaga?->empresa?->razao_social ?? '—',
                'franquia'         => $e->candidato?->franquia?->nome ?? '—',
                'status'           => $e->status,
                'observacao'       => $e->observacao,
                'salario_aprovado' => $e->salario_aprovado,
                'tipo_contrato'    => $e->tipo_contrato,
                'vaga_tipo_contrato' => $e->vaga?->tipo_contrato,
                'data_admissao'    => $e->data_admissao?->toDateString(),
                'data_saida'       => $e->data_saida?->toDateString(),
                'created_at'       => $e->created_at,
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

        // Kanban: agrupado por situação, sem paginação
        $grouped = $query->orderByDesc('created_at')->get()->groupBy('status');
        $statusKeys = ['enviado', 'visualizado', 'em_processo', 'aprovado', 'reprovado'];

        $data = [];
        foreach ($statusKeys as $key) {
            $data[$key] = ($grouped[$key] ?? collect())->map(fn($e) => [
                'candidato_id' => $e->candidato_id,
                'nome'         => $e->candidato?->user?->name,
                'vaga'         => $e->vaga?->titulo,
                'vaga_id'      => $e->vaga_id,
            ])->values();
        }

        return response()->json(['data' => $data]);
    }

    // GET /franquia/candidatos/pareceres
    public function pareceres(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $query = CandidatoParecer::with(['candidato.user:id,name', 'vaga:id,titulo'])
            ->where('franquia_id', $franquiaId);

        // Busca no servidor: a tela filtrava no navegador, sobre a página
        // aberta, e não encontrava nada fora dos 20 mais recentes.
        if ($request->filled('search')) {
            $termo = trim($request->search);
            $query->where(function ($q) use ($termo) {
                $q->whereHas('candidato.user', fn($u) => $u->where('name', 'like', "%{$termo}%"))
                  ->orWhereHas('vaga', fn($v) => $v->where('titulo', 'like', "%{$termo}%"));

                if (ctype_digit($termo)) {
                    $q->orWhere('id', (int) $termo);
                }
            });
        }

        // A empresa pode estar no parecer ou vir pela vaga — a tela mostra as
        // duas origens, então o filtro cobre as duas.
        if ($request->filled('empresa_id')) {
            $empresaId = $request->empresa_id;
            $query->where(function ($q) use ($empresaId) {
                $q->where('empresa_id', $empresaId)
                  ->orWhereHas('vaga', fn($v) => $v->where('empresa_id', $empresaId));
            });
        }

        if ($request->filled('status')) {
            $status = $request->status;
            // Parecer antigo tem status_aprovacao nulo e conta como pendente.
            $query->when($status === 'pendente',
                fn($q) => $q->where(fn($s) => $s->where('status_aprovacao', 'pendente')
                                                ->orWhereNull('status_aprovacao')),
                fn($q) => $q->where('status_aprovacao', $status),
            );
        }

        // Vinculação e admissão vivem no envio, não no parecer. O par
        // candidato+vaga é o que liga um ao outro.
        $filtrosEnvio = ['vinculo_de', 'vinculo_ate', 'admissao_de', 'admissao_ate'];

        if (collect($filtrosEnvio)->contains(fn($c) => $request->filled($c))) {
            $query->whereExists(function ($q) use ($request) {
                $q->selectRaw('1')
                  ->from('envios')
                  ->whereColumn('envios.candidato_id', 'candidato_pareceres.candidato_id')
                  ->whereColumn('envios.vaga_id', 'candidato_pareceres.vaga_id');

                if ($request->filled('vinculo_de'))   $q->whereDate('envios.created_at', '>=', $request->vinculo_de);
                if ($request->filled('vinculo_ate'))  $q->whereDate('envios.created_at', '<=', $request->vinculo_ate);
                if ($request->filled('admissao_de'))  $q->whereDate('envios.data_admissao', '>=', $request->admissao_de);
                if ($request->filled('admissao_ate')) $q->whereDate('envios.data_admissao', '<=', $request->admissao_ate);
            });
        }

        $perPage   = min((int) $request->query('per_page', 20), 100);
        $pareceres = $query->with('vaga.empresa:id,razao_social,nome_fantasia')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = $pareceres->getCollection()->map(fn($p) => [
            'id'               => $p->id,
            'candidato'        => $p->candidato ? [
                'id'              => $p->candidato_id,
                'nome'            => $p->candidato->user?->name ?? $p->candidato->nome,
                'cpf'             => $p->candidato->cpf,
                'telefone'        => $p->candidato->telefone,
                'email'           => $p->candidato->email ?? $p->candidato->user?->email,
                'data_nascimento' => $p->candidato->data_nascimento?->toDateString(),
                'escolaridade'    => $p->candidato->escolaridade,
                'cidade'          => $p->candidato->cidade,
                'estado'          => $p->candidato->estado,
                'bairro'          => $p->candidato->bairro,
                'logradouro'      => $p->candidato->logradouro,
            ] : null,
            'vaga'             => $p->vaga ? ['id' => $p->vaga_id, 'titulo' => $p->vaga->titulo] : null,
            'empresa_nome'     => $p->empresa?->razao_social ?? $p->empresa?->nome_fantasia ?? $p->vaga?->empresa?->razao_social ?? $p->vaga?->empresa?->nome_fantasia,
            'franquia_nome'    => $p->franquia?->nome,
            'texto'            => $p->texto,
            'nota'             => $p->nota,
            'dados'            => $p->dados,
            'status_aprovacao' => $p->status_aprovacao,
            'created_at'       => $p->created_at,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $pareceres->total(),
                'per_page'     => $pareceres->perPage(),
                'current_page' => $pareceres->currentPage(),
                'last_page'    => $pareceres->lastPage(),
            ],
        ]);
    }

    // PATCH /franquia/candidatos/pareceres/{id}/status
    public function atualizarStatusParecer(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $parecer = CandidatoParecer::where('franquia_id', $franquiaId)->findOrFail($id);

        $data = $request->validate([
            'status_aprovacao' => 'required|in:aprovado,reprovado',
        ]);

        $parecer->update(['status_aprovacao' => $data['status_aprovacao']]);

        return response()->json([
            'message' => 'Status do parecer atualizado.',
            'data'    => ['id' => $parecer->id, 'status_aprovacao' => $parecer->status_aprovacao],
        ]);
    }

    // GET /franquia/candidatos/{id}
    public function show(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $candidato = $this->candidatosVisiveisQuery($franquiaId, $vagaIds)
            ->with([
                'user:id,name,email',
                'franquia:id,nome',
                'documentos' => fn($q) => $q->where('ativo', true),
            ])
            ->findOrFail($id);

        // Mesma regra do histórico: todas as candidaturas, de todas as
        // franquias, com quem encaminhou identificado.
        $candidaturas = Envio::with(['vaga:id,titulo', 'franquia:id,nome'])
            ->where('candidato_id', $candidato->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($e) => [
                'vaga_id'    => $e->vaga_id,
                'titulo'     => $e->vaga?->titulo,
                'status'     => $e->status,
                'franquia'   => $e->franquia?->nome ?? 'Administração',
                'is_own'     => $e->franquia_id === $franquiaId,
                'updated_at' => $e->updated_at,
            ]);

        $curriculo = $candidato->documentos->firstWhere('tipo', 'curriculo')
            ?? $candidato->documentos->first();

        return response()->json(['data' => [
            'id'                       => $candidato->id,
            'nome'                     => $candidato->user?->name,
            'email'                    => $candidato->user?->email,
            'telefone'                 => $candidato->telefone,
            'cpf'                      => $candidato->cpf,
            'nascimento'               => $candidato->nascimento,
            'status'                   => $candidato->active ? 'ativo' : 'inativo',
            'cep'                      => $candidato->cep,
            'rua'                      => $candidato->rua,
            'numero'                   => $candidato->numero,
            'bairro'                   => $candidato->bairro,
            'complemento'              => $candidato->complemento,
            'cidade'                   => $candidato->cidade,
            'estado'                   => $candidato->estado,
            'tipo_cnh'                 => $candidato->tipo_cnh,
            'cargo_desejado'           => $candidato->cargo_desejado,
            'cargos_interesse'         => $candidato->cargos_interesse ?? [],
            'informacoes_pessoais'     => $candidato->apresentacao,
            'experiencia_profissional' => $candidato->experiencia_profissional,
            'educacao'                 => $candidato->educacao,
            'habilidades'              => $candidato->habilidades,
            'idiomas'                  => $candidato->idiomas,
            'informacoes_adicionais'   => $candidato->informacoes_adicionais,
            'disponibilidade'          => $candidato->disponibilidade,
            'franquia_responsavel'     => $candidato->franquia?->nome,
            // arquivo_url é o que habilita os botões de ver e baixar na tela.
            // Sem ele, o botão de download ficava permanentemente desabilitado,
            // mesmo para candidato com currículo anexado.
            'curriculo_ativo'          => $curriculo ? [
                'id'           => $curriculo->id,
                'arquivo_nome' => $curriculo->arquivo_nome,
                'arquivo_url'  => $this->urlArquivo($curriculo->arquivo_path),
            ] : null,
            'documentos'               => $candidato->documentos->map(fn($d) => [
                'id'           => $d->id,
                'tipo'         => $d->tipo,
                'arquivo_nome' => $d->arquivo_nome,
                'arquivo_url'  => $this->urlArquivo($d->arquivo_path),
            ])->values(),
            'candidaturas'             => $candidaturas,
        ]]);
    }

    // PUT /franquia/candidatos/{id}
    public function update(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $candidato = $this->candidatosVisiveisQuery($franquiaId, $vagaIds)->findOrFail($id);

        $validated = $request->validate([
            'nome'                      => 'nullable|string|max:255',
            'email'                     => ['nullable', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($candidato->user_id)],
            'status'                    => 'nullable|in:ativo,inativo',
            'cargo_desejado'            => 'nullable|string|max:100',
            'telefone'                  => 'nullable|string|max:20',
            'cep'                       => 'nullable|string|max:9',
            'bairro'                    => 'nullable|string|max:100',
            'rua'                       => 'nullable|string|max:255',
            'numero'                    => 'nullable|string|max:20',
            'complemento'               => 'nullable|string|max:100',
            'cidade'                    => 'nullable|string|max:100',
            'estado'                    => 'nullable|string|size:2',
            'tipo_cnh'                  => 'nullable|string|max:10',
            'cargos_interesse'          => 'nullable|array|max:8',
            'cargos_interesse.*'        => 'string|max:100',
            'informacoes_pessoais'      => 'nullable|string',
            'experiencia_profissional'  => 'nullable|string',
            'educacao'                  => 'nullable|string',
            'habilidades'               => 'nullable|string',
            'idiomas'                   => 'nullable|string|max:500',
            'informacoes_adicionais'    => 'nullable|string',
        ]);

        // 'nome' e 'email' pertencem ao User relacionado, nao ao Candidato
        $nome  = $validated['nome'] ?? null;
        $email = $validated['email'] ?? null;
        unset($validated['nome'], $validated['email']);

        // 'status' (ativo/inativo) e a coluna 'active' (boolean) traduzida
        if (array_key_exists('status', $validated)) {
            $validated['active'] = $validated['status'] === 'ativo';
            unset($validated['status']);
        }

        // a coluna real e 'apresentacao'; o form envia 'informacoes_pessoais'
        if (array_key_exists('informacoes_pessoais', $validated)) {
            $validated['apresentacao'] = $validated['informacoes_pessoais'];
            unset($validated['informacoes_pessoais']);
        }

        // Re-geocoda se o endereço mudou, ou se o candidato ainda não tinha coordenadas
        $enderecoMudou = !$candidato->latitude || collect(['rua', 'numero', 'bairro', 'cidade', 'estado'])
            ->some(fn($f) => array_key_exists($f, $validated) && $validated[$f] !== $candidato->{$f});

        $candidato->update($validated);

        if (($nome !== null || $email !== null) && $candidato->user) {
            $candidato->user->update(array_filter([
                'name'  => $nome,
                'email' => $email,
            ], fn($v) => $v !== null));
        }

        if ($enderecoMudou && $candidato->cidade) {
            $coords = $this->geocoder->geocode(
                $candidato->rua, $candidato->numero, $candidato->bairro, $candidato->cidade, $candidato->estado
            );
            if ($coords) {
                $candidato->update($coords);
            }
        }

        return response()->json(['message' => 'Candidato atualizado.', 'data' => $candidato->fresh('user')]);
    }

    // POST /franquia/candidatos/{candidatoId}/vincular
    public function vincular(Request $request, int $candidatoId)
    {
        $franquiaId = $this->tokenContextId($request);

        $request->validate(['vaga_id' => 'required|integer']);

        // Vaga própria OU vaga em que esta franquia foi convidada — mesma regra
        // de FranquiaVagaController::vincular. Exigir só a vaga própria travava
        // a operação: as vagas migradas pertencem todas à Unidade Matriz, e é o
        // admin que distribui cada uma às franquias pelo convite.
        $podeVincular = \App\Models\Vaga::whereKey($request->vaga_id)
            ->where(function ($q) use ($franquiaId) {
                $q->where('franquia_id', $franquiaId)
                  ->orWhereHas('franquiasCompartilhadas', fn($s) => $s->where('franquias.id', $franquiaId));
            })
            ->exists();

        if (!$podeVincular) {
            return response()->json([
                'message' => 'Esta vaga não está disponível para a sua franquia.',
            ], 403);
        }

        $candidato = Candidato::findOrFail($candidatoId);

        if (!$candidato->pareceres()->exists()) {
            return response()->json([
                'message' => 'Candidato precisa ter um parecer registrado antes de ser vinculado a uma vaga.',
            ], 422);
        }

        $curriculo = $candidato->documentos()->where('ativo', true)->first()
            ?? $candidato->documentos()->latest()->first();

        // Permite vincular candidatos do banco que ainda nao tem curriculo anexado.
        $envio = Envio::firstOrCreate(
            ['candidato_id' => $candidato->id, 'vaga_id' => $request->vaga_id],
            [
                'curriculo_id' => $curriculo?->id,
                'status'       => 'enviado',
                // origem='franquia': a empresa recebeu este candidato pelo canal agência
                'origem'       => 'franquia',
                // Quem encaminhou — é por aqui que o envio volta para a tela de
                // Status Candidatos de quem o registrou.
                'franquia_id'  => $franquiaId,
            ]
        );

        if (!$envio->wasRecentlyCreated) {
            return response()->json([
                'message' => 'Este candidato já está participando do processo seletivo desta vaga.',
            ], 422);
        }

        return response()->json([
            'message' => 'Candidato vinculado com sucesso.',
            'data'    => ['candidato_id' => $candidato->id, 'vaga_id' => $request->vaga_id, 'status' => $envio->status],
        ], 201);
    }

    /**
     * GET /franquia/candidatos/{id}/historico
     *
     * Histórico COMPLETO do candidato, de todas as franquias, identificando
     * quem encaminhou e quando (decisão do cliente em 11/08/2026).
     *
     * Antes era restrito ao escopo da própria franquia, e isso causava
     * retrabalho: uma franquia encaminhava o candidato para uma vaga do mesmo
     * cargo e da mesma empresa de onde ele já havia desistido meses antes, sem
     * ter como saber. O receio original — cópia de parecer entre franquias —
     * está resolvido em `pareceresCandidato`, que restringe o texto do parecer
     * a quem o escreveu. Aqui só trafega vaga, empresa, situação e data.
     */
    public function historico(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $envios = Envio::with([
                'vaga:id,titulo,empresa_id',
                'vaga.empresa:id,razao_social,nome_fantasia',
                'franquia:id,nome',
            ])
            ->where('candidato_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($e) => [
                'id'           => $e->id,
                'vaga_nome'    => $e->vaga?->titulo,
                'empresa_nome' => $e->vaga?->empresa?->razao_social ?? $e->vaga?->empresa?->nome_fantasia,
                // Sem franquia responsável = encaminhamento da operação central
                'franquia'     => $e->franquia?->nome ?? 'Administração',
                'is_own'       => $e->franquia_id === $franquiaId,
                'status'       => $e->status,
                'vinculado_em' => $e->created_at,
            ]);

        return response()->json(['data' => $envios]);
    }

    // GET /franquia/candidatos/{id}/pareceres
    public function pareceresCandidato(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $pareceres = CandidatoParecer::with(['criador:id,name', 'vaga:id,titulo,empresa_id', 'vaga.empresa:id,razao_social', 'empresa:id,razao_social', 'franquia:id,nome'])
            ->where('candidato_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) use ($franquiaId) {
                $isOwn = $p->franquia_id === $franquiaId;
                return [
                    'id'                => $p->id,
                    'parecer'           => $isOwn ? $p->texto : '[Conteúdo restrito à franquia de origem]',
                    'nota'              => $isOwn ? $p->nota : null,
                    'cargo_pretendido'  => $p->vaga?->titulo,
                    'empresa_nome'      => $p->empresa?->razao_social ?? $p->vaga?->empresa?->razao_social,
                    'criado_por_nome'   => $p->criador?->name,
                    'franquia_nome'     => $p->franquia?->nome ?? ($p->franquia_id ? 'Outra Franquia' : 'Administração'),
                    'dados'             => $isOwn ? $p->dados : null,
                    'is_own'            => $isOwn,
                    'created_at'        => $p->created_at,
                ];
            });

        return response()->json(['data' => $pareceres]);
    }

    // POST /franquia/candidatos/{id}/parecer
    public function storeParecer(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $validated = $request->validate([
            'vaga_id'    => 'nullable|integer|exists:vagas,id',
            'empresa_id' => 'nullable|integer|exists:empresas,id',
            'texto'      => 'required|string|max:5000',
            'nota'       => 'nullable|integer|min:1|max:5',
            'dados'      => 'nullable|array',
        ]);

        $parecer = CandidatoParecer::create([
            'franquia_id' => $franquiaId,
            'candidato_id'=> $id,
            'vaga_id'     => $validated['vaga_id'] ?? null,
            'empresa_id'  => $validated['empresa_id'] ?? null,
            'criado_por'  => $request->user()->id,
            'texto'       => $validated['texto'],
            'nota'        => $validated['nota'] ?? null,
            'dados'       => $validated['dados'] ?? null,
        ]);

        // Teste informado no parecer aparece na tela "Testes" da empresa
        app(\App\Services\SincronizacaoAgendamentosParecer::class)->doParecer($parecer);

        return response()->json([
            'message' => 'Parecer registrado.',
            'data'    => ['id' => $parecer->id, 'nota' => $parecer->nota],
        ], 201);
    }

    // PUT /franquia/candidatos/parecer/{id}
    public function updateParecer(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $parecer = CandidatoParecer::where('franquia_id', $franquiaId)->findOrFail($id);

        $validated = $request->validate([
            'texto' => 'required|string|max:5000',
            'nota'  => 'nullable|integer|min:1|max:5',
            'dados' => 'nullable|array',
        ]);

        $parecer->update(array_merge([
            'texto' => $validated['texto'],
            'nota'  => $validated['nota'] ?? null,
        ], array_key_exists('dados', $validated) ? ['dados' => $validated['dados']] : []));

        // Alteração do teste no parecer reflete na tela "Testes" da empresa
        app(\App\Services\SincronizacaoAgendamentosParecer::class)->doParecer($parecer);

        return response()->json([
            'message' => 'Parecer atualizado com sucesso.',
            'data'    => $parecer,
        ]);
    }

    // DELETE /franquia/candidatos/parecer/{id}
    public function destroyParecer(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $parecer = CandidatoParecer::where('franquia_id', $franquiaId)->findOrFail($id);
        $parecer->delete();

        return response()->json([
            'message' => 'Parecer excluído com sucesso.',
        ]);
    }

    // GET /franquia/candidatos/discs  (lista de resultados DISC já aplicados)
    public function discs(Request $request)
    {
        $franquiaId   = $this->tokenContextId($request);
        $vagaIds      = $this->vagaIds($franquiaId);
        $candidatoIds = $this->candidatosVisiveisQuery($franquiaId, $vagaIds)->pluck('id');

        $query = \App\Models\CandidatoDisc::whereIn('candidato_id', $candidatoIds)
            ->with(['candidato.user:id,name', 'aplicador:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->whereHas('candidato.user', fn($q) => $q->where('name', 'like', $term));
        }

        $resultados = $query->paginate(min((int) $request->query('per_page', 20), 100));

        $items = collect($resultados->items())->map(fn($d) => [
            'id'                => $d->id,
            'candidato_id'      => $d->candidato_id,
            'candidato_nome'    => $d->candidato?->user?->name,
            'perfil_dominante'  => $d->perfil_dominante,
            'score_d'           => $d->score_d,
            'score_i'           => $d->score_i,
            'score_s'           => $d->score_s,
            'score_c'           => $d->score_c,
            'aplicado_por_nome' => $d->aplicador?->name,
            'created_at'        => $d->created_at,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $resultados->total(),
                'per_page'     => $resultados->perPage(),
                'current_page' => $resultados->currentPage(),
                'last_page'    => $resultados->lastPage(),
            ],
        ]);
    }

    // GET /franquia/candidatos/{id}/disc
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

    // POST /franquia/candidatos/{id}/disc
    public function discStore(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $candidato = $this->candidatosVisiveisQuery($franquiaId, $vagaIds)->findOrFail($id);

        $validated = $request->validate([
            'perfil_dominante' => 'required|string|in:D,I,S,C',
            'score_d'          => 'required|integer|min:0|max:100',
            'score_i'          => 'required|integer|min:0|max:100',
            'score_s'          => 'required|integer|min:0|max:100',
            'score_c'          => 'required|integer|min:0|max:100',
            'respostas'        => 'nullable|array',
        ]);

        $disc = \App\Models\CandidatoDisc::create($validated + [
            'candidato_id' => $candidato->id,
            'aplicado_por' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Teste DISC registrado.', 'data' => ['id' => $disc->id]], 201);
    }

    // PATCH /franquia/candidatos/{candidatoId}/vagas/{vagaId}/status
    public function updateStatus(Request $request, int $candidatoId, int $vagaId)
    {
        $franquiaId = $this->tokenContextId($request);
        $vagaIds    = $this->vagaIds($franquiaId);

        $data = $request->validate([
            'status'           => 'required|in:enviado,visualizado,em_processo,em_entrevista,pendente,aprovado,reprovado,desistiu,reposicao',
            'observacao'       => 'nullable|string',
            'salario_aprovado' => 'nullable|numeric|min:0',
            'tipo_contrato'    => 'nullable|string|max:50',
            'data_admissao'    => 'nullable|date',
            'data_saida'       => 'nullable|date',
        ]);

        // Pode alterar o que ela mesma encaminhou (envios.franquia_id) ou o que
        // chegou nas vagas dela — exatamente o que a tela lista. Exigir a vaga
        // própria travava todo mundo: as vagas migradas são da Matriz, e nem a
        // franquia que fez o encaminhamento conseguia mudar o status.
        $envio = $this->escopoEnvios(
            Envio::where('candidato_id', $candidatoId)->where('vaga_id', $vagaId),
            $franquiaId,
            $vagaIds
        )->first();

        if (!$envio) {
            return response()->json([
                'message' => 'Este vínculo não pertence à sua franquia.',
            ], 403);
        }

        // status sempre; demais campos apenas quando enviados pelo front
        $envio->fill([
            'status' => $data['status'],
            // Mesmo processo seletivo: reflete no painel da empresa
            'status_empresa' => Envio::statusEmpresaPara($data['status']),
        ]);
        foreach (['observacao', 'salario_aprovado', 'tipo_contrato', 'data_admissao', 'data_saida'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $envio->{$campo} = $data[$campo];
            }
        }
        if ($data['status'] === 'aprovado' && empty($envio->data_admissao)) {
            $envio->data_admissao = now()->toDateString();
        }
        $envio->save();

        return response()->json(['message' => 'Status atualizado.', 'status' => $envio->status]);
    }
}
