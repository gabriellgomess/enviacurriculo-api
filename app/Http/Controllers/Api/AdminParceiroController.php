<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parceiro;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminParceiroController extends Controller
{
    public function index(Request $request)
    {
        $query = Parceiro::with('user:id,name,email');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('nome_empresa', 'like', "%{$s}%")
                  ->orWhere('razao_social', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('cnpj', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%");
            });
        }

        if ($request->filled('estado'))  $query->where('estado', $request->estado);
        if ($request->filled('active'))  $query->where('active', $request->boolean('active'));

        $parceiros = $query->orderBy('nome_empresa')->paginate(20);

        $meta = [
            'total'   => Parceiro::count(),
            'ativos'  => Parceiro::where('active', true)->count(),
            'inativos'=> Parceiro::where('active', false)->count(),
        ];

        return response()->json([
            'data' => $parceiros->items(),
            'meta' => array_merge($parceiros->toArray(), $meta),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome_empresa'   => 'required|string|max:255',
            'razao_social'   => 'nullable|string|max:255',
            'cnpj'           => 'nullable|string|max:18|unique:parceiros,cnpj',
            'franquia_id'    => 'nullable|integer|exists:franquias,id',
            'email'          => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'telefone'       => 'nullable|string|max:20',
            'descricao'      => 'nullable|string',
            'cep'            => 'nullable|string|max:9',
            'rua'            => 'nullable|string|max:255',
            'numero'         => 'nullable|string|max:20',
            'complemento'    => 'nullable|string|max:255',
            'bairro'         => 'nullable|string|max:100',
            'cidade'         => 'nullable|string|max:100',
            'estado'         => 'nullable|string|size:2',
            'especialidades' => 'nullable|array',
            'especialidades.*' => 'string|max:100',
            'active'         => 'boolean',
            'password'       => 'nullable|string|min:6',
        ]);

        return DB::transaction(function () use ($validated) {
            $user = null;

            if (!empty($validated['email']) && !empty($validated['password'])) {
                $user = User::create([
                    'name'     => $validated['nome_empresa'],
                    'email'    => $validated['email'],
                    'phone'    => $validated['telefone'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'active'   => $validated['active'] ?? true,
                ]);
                UserRole::create(['user_id' => $user->id, 'role' => 'parceiro']);
            }

            $parceiro = Parceiro::create(array_merge(
                $validated,
                ['user_id' => $user?->id]
            ));

            if ($user) {
                UserContext::create([
                    'user_id'    => $user->id,
                    'role'       => 'parceiro',
                    'context_id' => $parceiro->id,
                ]);
            }

            return response()->json($parceiro->load('user:id,name,email'), 201);
        });
    }

    public function show(Parceiro $parceiro)
    {
        $ctx  = UserContext::where('role', 'parceiro')->where('context_id', $parceiro->id)->with('user')->first();
        $user = $ctx?->user;

        return response()->json([
            ...$parceiro->toArray(),
            'login_email' => $user?->email,
            'user_id'     => $user?->id,
        ]);
    }

    public function update(Request $request, Parceiro $parceiro)
    {
        $validated = $request->validate([
            'nome_empresa'   => 'required|string|max:255',
            'razao_social'   => 'nullable|string|max:255',
            'cnpj'           => 'nullable|string|max:18|unique:parceiros,cnpj,' . $parceiro->id,
            'franquia_id'    => 'nullable|integer|exists:franquias,id',
            'email'          => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($parceiro->user_id)],
            'telefone'       => 'nullable|string|max:20',
            'descricao'      => 'nullable|string',
            'cep'            => 'nullable|string|max:9',
            'rua'            => 'nullable|string|max:255',
            'numero'         => 'nullable|string|max:20',
            'complemento'    => 'nullable|string|max:255',
            'bairro'         => 'nullable|string|max:100',
            'cidade'         => 'nullable|string|max:100',
            'estado'         => 'nullable|string|size:2',
            'especialidades' => 'nullable|array',
            'especialidades.*' => 'string|max:100',
            'active'         => 'boolean',
            'password'       => 'nullable|string|min:6',
        ]);

        return DB::transaction(function () use ($validated, $parceiro) {
            $password = $validated['password'] ?? null;
            unset($validated['password']);
            $parceiro->update($validated);

            $ctx  = UserContext::where('role', 'parceiro')->where('context_id', $parceiro->id)->with('user')->first();
            $user = $ctx?->user;

            if ($user) {
                $upd = [
                    'name'   => $validated['nome_empresa'],
                    'phone'  => $validated['telefone'] ?? $user->phone,
                    'active' => $validated['active'] ?? $user->active,
                ];
                if (!empty($validated['email'])) $upd['email'] = $validated['email'];
                if ($password) $upd['password'] = Hash::make($password);
                $user->update($upd);
            } elseif (!empty($validated['email']) && $password) {
                $newUser = User::create([
                    'name'     => $validated['nome_empresa'],
                    'email'    => $validated['email'],
                    'phone'    => $validated['telefone'] ?? null,
                    'password' => Hash::make($password),
                    'active'   => $validated['active'] ?? true,
                ]);
                UserRole::create(['user_id' => $newUser->id, 'role' => 'parceiro']);
                UserContext::create([
                    'user_id'    => $newUser->id,
                    'role'       => 'parceiro',
                    'context_id' => $parceiro->id,
                ]);
                $parceiro->update(['user_id' => $newUser->id]);
            }

            return response()->json($parceiro->fresh()->load('user:id,name,email'));
        });
    }

    public function destroy(Parceiro $parceiro)
    {
        $parceiro->delete();
        return response()->json(['message' => 'Parceiro removido.']);
    }

    public function toggleActive(Parceiro $parceiro)
    {
        $parceiro->update(['active' => !$parceiro->active]);

        $ctx  = UserContext::where('role', 'parceiro')->where('context_id', $parceiro->id)->with('user')->first();
        $ctx?->user?->update(['active' => $parceiro->active]);

        return response()->json($parceiro->fresh());
    }

    public function relatorios()
    {
        $visualizacoes = \App\Models\ParceiroVisualizacao::with('parceiro:id,nome_empresa')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $mapped = $visualizacoes->map(fn($v) => [
            'id'                     => $v->id,
            'parceiro'               => ['nome_empresa' => $v->parceiro ? $v->parceiro->nome_empresa : '-'],
            'visualizado_por_nome'   => $v->empresa_nome . ' (' . $v->usuario_nome . ')',
            'tipo_visualizacao'      => $v->tipo,
            'created_at'             => $v->created_at->toIso8601String(),
        ]);

        return response()->json([
            'data' => $mapped
        ]);
    }
}
