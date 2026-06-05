<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

const EXTRA_PANELS = ['empresa', 'franquia', 'candidato', 'parceiro'];

class AdminPermissionController extends Controller
{
    public function index()
    {
        $admins = User::whereHas('roles', fn($q) => $q->where('role', 'admin'))
            ->with(['roles', 'adminPermission'])
            ->orderBy('name')
            ->get()
            ->map(fn($user) => $this->formatAdmin($user));

        return response()->json($admins);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => 'required|email|unique:users,email',
            'password'         => 'required|string|min:6',
            'acesso_total'     => 'boolean',
            'menus_permitidos' => 'array',
            'menus_permitidos.*' => 'string',
            'paineis_acesso'   => 'array',
            'paineis_acesso.*' => 'in:empresa,franquia,candidato,parceiro',
        ]);

        if (!$request->boolean('acesso_total') && empty($request->menus_permitidos)) {
            return response()->json(['message' => 'Selecione pelo menos um menu ou ative o acesso total.'], 422);
        }

        DB::transaction(function () use ($request, &$user) {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => $request->password,
                'active'   => true,
            ]);

            // Role admin sempre
            UserRole::create(['user_id' => $user->id, 'role' => 'admin']);

            // Roles extras (paineis adicionais)
            foreach ($request->paineis_acesso ?? [] as $painel) {
                UserRole::create(['user_id' => $user->id, 'role' => $painel]);
            }

            AdminPermission::create([
                'user_id'          => $user->id,
                'acesso_total'     => $request->boolean('acesso_total', true),
                'menus_permitidos' => $request->boolean('acesso_total') ? [] : $request->menus_permitidos,
            ]);
        });

        return response()->json($this->formatAdmin($user->fresh(['roles', 'adminPermission'])), 201);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'             => 'sometimes|string|max:255',
            'email'            => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password'         => 'sometimes|nullable|string|min:6',
            'acesso_total'     => 'boolean',
            'menus_permitidos' => 'array',
            'menus_permitidos.*' => 'string',
            'paineis_acesso'   => 'array',
            'paineis_acesso.*' => 'in:empresa,franquia,candidato,parceiro',
        ]);

        DB::transaction(function () use ($request, $user) {
            $data = $request->only('name', 'email');
            if ($request->filled('password')) {
                $data['password'] = $request->password;
            }
            $user->update($data);

            // Recria roles extras (remove os antigos e insere os novos)
            $user->roles()->whereIn('role', EXTRA_PANELS)->delete();
            foreach ($request->paineis_acesso ?? [] as $painel) {
                UserRole::create(['user_id' => $user->id, 'role' => $painel]);
            }

            AdminPermission::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'acesso_total'     => $request->boolean('acesso_total', true),
                    'menus_permitidos' => $request->boolean('acesso_total') ? [] : ($request->menus_permitidos ?? []),
                ]
            );
        });

        return response()->json($this->formatAdmin($user->fresh(['roles', 'adminPermission'])));
    }

    public function destroy(User $user)
    {
        if ($user->id === request()->user()->id) {
            return response()->json(['message' => 'Você não pode remover sua própria conta.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Administrador removido com sucesso.']);
    }

    private function formatAdmin(User $user): array
    {
        $perm        = $user->adminPermission;
        $roleNames   = $user->roles->pluck('role')->toArray();
        $paineis     = array_values(array_intersect($roleNames, EXTRA_PANELS));

        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'active'           => $user->active,
            'acesso_total'     => $perm?->acesso_total ?? true,
            'menus_permitidos' => $perm?->menus_permitidos ?? [],
            'paineis_acesso'   => $paineis,
        ];
    }
}
