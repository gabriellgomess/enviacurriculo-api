<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Franquia;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use App\Services\GeocodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FranquiaController extends Controller
{
    public function __construct(private GeocodeService $geocode) {}

    public function index(Request $request)
    {
        $query = Franquia::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nome', 'like', "%{$search}%")
                  ->orWhere('responsavel', 'like', "%{$search}%")
                  ->orWhere('cidade', 'like', "%{$search}%")
                  ->orWhere('cnpj', 'like', "%{$search}%")
                  ->orWhere('codigo', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $perPage = min((int) $request->get('per_page', 20), 200);
        $franquias = $query->with('createdBy:id,name')->orderBy('nome')->paginate($perPage);

        // Métricas para os cards do topo
        $meta = [
            'total'   => Franquia::count(),
            'ativas'  => Franquia::where('active', true)->count(),
            'inativas'=> Franquia::where('active', false)->count(),
        ];

        return response()->json([
            'data' => $franquias->items(),
            'meta' => array_merge($franquias->toArray(), $meta),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tipo'                  => 'required|in:premium,start',
            'nome'                  => 'required|string|max:255',
            'cpf'                   => 'nullable|string|max:14',
            'data_nascimento'       => 'nullable|date',
            'responsavel'           => 'nullable|string|max:255',
            'email'                 => 'nullable|email|max:255|unique:users,email',
            'email_franqueado'      => 'nullable|email|max:255',
            'telefone'              => 'nullable|string|max:20',
            'data_inicio_parceria'  => 'nullable|date',
            'data_termino_parceria' => 'nullable|date|after_or_equal:data_inicio_parceria',
            // Endereço pessoal
            'cep'                   => 'nullable|string|max:9',
            'logradouro'            => 'nullable|string|max:255',
            'numero'                => 'nullable|string|max:20',
            'complemento'           => 'nullable|string|max:100',
            'bairro'                => 'nullable|string|max:100',
            'cidade'                => 'nullable|string|max:100',
            'estado'                => 'nullable|string|size:2',
            // Dados da empresa
            'cnpj'                  => 'nullable|string|max:18|unique:franquias,cnpj',
            'descricao'             => 'nullable|string',
            'cep_empresa'           => 'nullable|string|max:9',
            'logradouro_empresa'    => 'nullable|string|max:255',
            'numero_empresa'        => 'nullable|string|max:20',
            'complemento_empresa'   => 'nullable|string|max:100',
            'bairro_empresa'        => 'nullable|string|max:100',
            'cidade_empresa'        => 'nullable|string|max:100',
            'estado_empresa'        => 'nullable|string|size:2',
            // Bancário
            'nome_banco'            => 'nullable|string|max:100',
            'codigo_banco'          => 'nullable|string|max:10',
            'agencia'               => 'nullable|string|max:20',
            'numero_conta'          => 'nullable|string|max:20',
            'tipo_conta'            => 'nullable|in:corrente,poupanca',
            'chave_pix'             => 'nullable|string|max:255',
            // Permissões e login
            'menus_permitidos'      => 'nullable|array',
            'active'                => 'boolean',
            'password'              => 'nullable|string|min:6',
        ], [
            'email.unique' => 'Já existe um usuário cadastrado com este e-mail.',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            // Geocoding do endereço pessoal
            $coords = $this->geocode->geocode(
                logradouro: $validated['logradouro'] ?? null,
                numero:     $validated['numero']     ?? null,
                bairro:     $validated['bairro']     ?? null,
                cidade:     $validated['cidade']     ?? null,
                estado:     $validated['estado']     ?? null,
            );

            // Geocoding do endereço da empresa
            $coordsEmpresa = $this->geocode->geocode(
                logradouro: $validated['logradouro_empresa'] ?? null,
                numero:     $validated['numero_empresa']     ?? null,
                bairro:     $validated['bairro_empresa']     ?? null,
                cidade:     $validated['cidade_empresa']     ?? null,
                estado:     $validated['estado_empresa']     ?? null,
            );

            $franquia = Franquia::create(array_merge(
                $validated,
                [
                    'latitude'          => $coords['latitude']           ?? null,
                    'longitude'         => $coords['longitude']          ?? null,
                    'latitude_empresa'  => $coordsEmpresa['latitude']    ?? null,
                    'longitude_empresa' => $coordsEmpresa['longitude']   ?? null,
                    'created_by'        => $request->user()->id,
                ]
            ));

            // Gera código único
            $franquia->update(['codigo' => 'FR-' . str_pad($franquia->id, 4, '0', STR_PAD_LEFT)]);

            // Cria usuário de acesso se email fornecido
            if (!empty($validated['email']) && !empty($validated['password'])) {
                $user = User::create([
                    'name'     => $validated['responsavel'] ?? $validated['nome'],
                    'email'    => $validated['email'],
                    'phone'    => $validated['telefone'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'active'   => $validated['active'] ?? true,
                ]);

                UserRole::create(['user_id' => $user->id, 'role' => 'franquia']);

                UserContext::create([
                    'user_id'    => $user->id,
                    'role'       => 'franquia',
                    'context_id' => $franquia->id,
                ]);
            }

            return response()->json($franquia->fresh(), 201);
        });
    }

    public function show(Franquia $franquia)
    {
        $franquia->load('createdBy:id,name');
        $user = $franquia->user();

        return response()->json([
            ...$franquia->toArray(),
            'login_email' => $user?->email,
            'user_id'     => $user?->id,
            'criado_por'  => $franquia->createdBy?->name,
        ]);
    }

    public function update(Request $request, Franquia $franquia)
    {
        $validated = $request->validate([
            'tipo'                  => 'required|in:premium,start',
            'nome'                  => 'required|string|max:255',
            'cpf'                   => 'nullable|string|max:14',
            'data_nascimento'       => 'nullable|date',
            'responsavel'           => 'nullable|string|max:255',
            'email'                 => ['nullable', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($franquia->user()?->id)],
            'email_franqueado'      => 'nullable|email|max:255',
            'telefone'              => 'nullable|string|max:20',
            'data_inicio_parceria'  => 'nullable|date',
            'data_termino_parceria' => 'nullable|date|after_or_equal:data_inicio_parceria',
            'cep'                   => 'nullable|string|max:9',
            'logradouro'            => 'nullable|string|max:255',
            'numero'                => 'nullable|string|max:20',
            'complemento'           => 'nullable|string|max:100',
            'bairro'                => 'nullable|string|max:100',
            'cidade'                => 'nullable|string|max:100',
            'estado'                => 'nullable|string|size:2',
            'cnpj'                  => 'nullable|string|max:18|unique:franquias,cnpj,' . $franquia->id,
            'descricao'             => 'nullable|string',
            'cep_empresa'           => 'nullable|string|max:9',
            'logradouro_empresa'    => 'nullable|string|max:255',
            'numero_empresa'        => 'nullable|string|max:20',
            'complemento_empresa'   => 'nullable|string|max:100',
            'bairro_empresa'        => 'nullable|string|max:100',
            'cidade_empresa'        => 'nullable|string|max:100',
            'estado_empresa'        => 'nullable|string|size:2',
            'nome_banco'            => 'nullable|string|max:100',
            'codigo_banco'          => 'nullable|string|max:10',
            'agencia'               => 'nullable|string|max:20',
            'numero_conta'          => 'nullable|string|max:20',
            'tipo_conta'            => 'nullable|in:corrente,poupanca',
            'chave_pix'             => 'nullable|string|max:255',
            'menus_permitidos'      => 'nullable|array',
            'active'                => 'boolean',
            'password'              => 'nullable|string|min:6',
        ], [
            'email.unique' => 'Já existe um usuário cadastrado com este e-mail.',
        ]);

        return DB::transaction(function () use ($validated, $franquia) {
            // Re-geocoda apenas se endereço pessoal mudou
            if ($franquia->cidade !== ($validated['cidade'] ?? null) ||
                $franquia->estado !== ($validated['estado'] ?? null)) {
                $coords = $this->geocode->geocode(
                    logradouro: $validated['logradouro'] ?? null,
                    numero:     $validated['numero']     ?? null,
                    bairro:     $validated['bairro']     ?? null,
                    cidade:     $validated['cidade']     ?? null,
                    estado:     $validated['estado']     ?? null,
                );
                $validated['latitude']  = $coords['latitude']  ?? $franquia->latitude;
                $validated['longitude'] = $coords['longitude'] ?? $franquia->longitude;
            }

            // Re-geocoda endereço empresa se mudou
            if ($franquia->cidade_empresa !== ($validated['cidade_empresa'] ?? null) ||
                $franquia->estado_empresa !== ($validated['estado_empresa'] ?? null)) {
                $coordsEmpresa = $this->geocode->geocode(
                    logradouro: $validated['logradouro_empresa'] ?? null,
                    numero:     $validated['numero_empresa']     ?? null,
                    bairro:     $validated['bairro_empresa']     ?? null,
                    cidade:     $validated['cidade_empresa']     ?? null,
                    estado:     $validated['estado_empresa']     ?? null,
                );
                $validated['latitude_empresa']  = $coordsEmpresa['latitude']  ?? $franquia->latitude_empresa;
                $validated['longitude_empresa'] = $coordsEmpresa['longitude'] ?? $franquia->longitude_empresa;
            }

            unset($validated['password']);
            $franquia->update($validated);

            // Atualiza ou cria usuário vinculado
            $user = $franquia->user();
            $password = request('password');

            if ($user) {
                $userUpdate = [
                    'name'   => $validated['responsavel'] ?? $validated['nome'],
                    'email'  => $validated['email'] ?? $user->email,
                    'phone'  => $validated['telefone'] ?? $user->phone,
                    'active' => $validated['active'] ?? $user->active,
                ];
                if ($password) {
                    $userUpdate['password'] = Hash::make($password);
                }
                $user->update($userUpdate);
            } elseif (!empty($validated['email']) && $password) {
                $newUser = User::create([
                    'name'     => $validated['responsavel'] ?? $validated['nome'],
                    'email'    => $validated['email'],
                    'phone'    => $validated['telefone'] ?? null,
                    'password' => Hash::make($password),
                    'active'   => $validated['active'] ?? true,
                ]);
                UserRole::create(['user_id' => $newUser->id, 'role' => 'franquia']);
                UserContext::create([
                    'user_id'    => $newUser->id,
                    'role'       => 'franquia',
                    'context_id' => $franquia->id,
                ]);
            }

            return response()->json($franquia->fresh());
        });
    }

    public function destroy(Franquia $franquia)
    {
        $franquia->delete();

        return response()->json(['message' => 'Franquia removida com sucesso.']);
    }

    public function toggleActive(Franquia $franquia)
    {
        $franquia->update(['active' => !$franquia->active]);

        // Sincroniza status do usuário vinculado
        $user = $franquia->user();
        $user?->update(['active' => $franquia->active]);

        return response()->json($franquia->fresh());
    }

    public function mapa()
    {
        $franquias = Franquia::whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('nome')
            ->get([
                'id', 'codigo', 'nome', 'tipo', 'responsavel',
                'email', 'telefone', 'cidade', 'estado',
                'latitude', 'longitude', 'active',
            ]);

        return response()->json($franquias);
    }

    public function relatorio()
    {
        $franquias = Franquia::orderBy('nome')->get([
            'id', 'codigo', 'nome', 'tipo', 'responsavel',
            'email', 'email_franqueado', 'telefone',
            'cidade', 'estado',
            'data_inicio_parceria', 'data_termino_parceria',
            'active',
        ]);

        $porTipo   = $franquias->groupBy('tipo')->map->count();
        $porEstado = $franquias->groupBy('estado')->map->count()->sortDesc();

        return response()->json([
            'franquias'  => $franquias,
            'totais' => [
                'total'   => $franquias->count(),
                'ativas'  => $franquias->where('active', true)->count(),
                'inativas'=> $franquias->where('active', false)->count(),
                'premium' => $porTipo->get('premium', 0),
                'start'   => $porTipo->get('start', 0),
            ],
            'por_estado' => $porEstado,
        ]);
    }
}
