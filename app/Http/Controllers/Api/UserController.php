<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->orderBy('name')->get()->map(function ($user) {
            return [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'phone'  => $user->phone,
                'active' => $user->active,
                'roles'  => $user->getRoleNames(),
            ];
        });

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:8|confirmed',
            'phone'      => 'nullable|string|max:20',
            'roles'      => 'required|array|min:1',
            'roles.*'    => 'in:admin,empresa,franquia,candidato,parceiro',
            'contexts'   => 'nullable|array',
            'contexts.*.role'       => 'required_with:contexts|in:empresa,franquia,candidato,parceiro',
            'contexts.*.context_id' => 'required_with:contexts|integer',
        ]);

        DB::transaction(function () use ($request, &$user) {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => $request->password,
                'phone'    => $request->phone,
                'active'   => true,
            ]);

            foreach ($request->roles as $role) {
                UserRole::create(['user_id' => $user->id, 'role' => $role]);
            }

            if ($request->contexts) {
                foreach ($request->contexts as $ctx) {
                    UserContext::create([
                        'user_id'    => $user->id,
                        'role'       => $ctx['role'],
                        'context_id' => $ctx['context_id'],
                    ]);
                }
            }
        });

        return response()->json([
            'id'     => $user->id,
            'name'   => $user->name,
            'email'  => $user->email,
            'roles'  => $request->roles,
        ], 201);
    }

    public function show(User $user)
    {
        $user->load('roles', 'contexts');

        return response()->json([
            'id'       => $user->id,
            'name'     => $user->name,
            'email'    => $user->email,
            'phone'    => $user->phone,
            'active'   => $user->active,
            'roles'    => $user->getRoleNames(),
            'contexts' => $user->contexts->map(fn($c) => [
                'role'       => $c->role,
                'context_id' => $c->context_id,
            ]),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:8|confirmed',
            'phone'    => 'nullable|string|max:20',
            'active'   => 'sometimes|boolean',
            'roles'    => 'sometimes|array|min:1',
            'roles.*'  => 'in:admin,empresa,franquia,candidato,parceiro',
            'contexts'              => 'nullable|array',
            'contexts.*.role'       => 'required_with:contexts|in:empresa,franquia,candidato,parceiro',
            'contexts.*.context_id' => 'required_with:contexts|integer',
        ]);

        DB::transaction(function () use ($request, $user) {
            $user->update($request->only('name', 'email', 'password', 'phone', 'active'));

            if ($request->has('roles')) {
                $user->roles()->delete();
                foreach ($request->roles as $role) {
                    UserRole::create(['user_id' => $user->id, 'role' => $role]);
                }
            }

            if ($request->has('contexts')) {
                $user->contexts()->delete();
                foreach ($request->contexts as $ctx) {
                    UserContext::create([
                        'user_id'    => $user->id,
                        'role'       => $ctx['role'],
                        'context_id' => $ctx['context_id'],
                    ]);
                }
            }
        });

        return response()->json(['message' => 'Usuário atualizado com sucesso.']);
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'Usuário removido com sucesso.']);
    }

    public function toggleActive(User $user)
    {
        $user->update(['active' => !$user->active]);

        return response()->json([
            'message' => $user->active ? 'Usuário ativado.' : 'Usuário desativado.',
            'active'  => $user->active,
        ]);
    }
}
