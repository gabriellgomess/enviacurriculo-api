<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\EmpresaSubUsuario;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmpresaSubUsuarioController extends Controller
{
    use HasTokenContext;

    private function present(EmpresaSubUsuario $s): array
    {
        return [
            'id'               => $s->id,
            'nome'             => $s->user?->name,
            'email'            => $s->user?->email,
            'ativo'            => $s->ativo,
            'menus_permitidos' => $s->menus_permitidos ?? [],
            'created_at'       => $s->created_at,
        ];
    }

    // GET /empresa/sub-usuarios
    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $subs = EmpresaSubUsuario::where('empresa_id', $empresaId)
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $subs->map(fn($s) => $this->present($s))]);
    }

    // POST /empresa/sub-usuarios
    public function store(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate([
            'nome'               => 'required|string|max:255',
            'email'              => 'required|email|max:255|unique:users,email',
            'senha'              => 'required|string|min:6',
            'menus_permitidos'   => 'nullable|array',
            'menus_permitidos.*' => 'string',
        ]);

        $sub = DB::transaction(function () use ($data, $empresaId) {
            $user = User::create([
                'name'     => $data['nome'],
                'email'    => $data['email'],
                'password' => Hash::make($data['senha']),
                'active'   => true,
            ]);

            UserRole::firstOrCreate(['user_id' => $user->id, 'role' => 'empresa']);
            UserContext::create([
                'user_id'    => $user->id,
                'role'       => 'empresa',
                'context_id' => $empresaId,
            ]);

            return EmpresaSubUsuario::create([
                'empresa_id'       => $empresaId,
                'user_id'          => $user->id,
                'menus_permitidos' => $data['menus_permitidos'] ?? [],
                'ativo'            => true,
            ]);
        });

        return response()->json($this->present($sub->load('user:id,name,email')), 201);
    }

    // PUT /empresa/sub-usuarios/{id}
    public function update(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $sub = EmpresaSubUsuario::where('empresa_id', $empresaId)->with('user')->findOrFail($id);

        $data = $request->validate([
            'nome'               => 'required|string|max:255',
            'email'              => 'required|email|max:255|unique:users,email,' . $sub->user_id,
            'senha'              => 'nullable|string|min:6',
            'menus_permitidos'   => 'nullable|array',
            'menus_permitidos.*' => 'string',
        ]);

        DB::transaction(function () use ($data, $sub) {
            $userUpdate = [
                'name'  => $data['nome'],
                'email' => $data['email'],
            ];
            if (!empty($data['senha'])) {
                $userUpdate['password'] = Hash::make($data['senha']);
            }
            $sub->user?->update($userUpdate);

            $sub->update(['menus_permitidos' => $data['menus_permitidos'] ?? []]);
        });

        return response()->json($this->present($sub->fresh('user:id,name,email')));
    }

    // PATCH /empresa/sub-usuarios/{id}/toggle-active
    public function toggleActive(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $sub = EmpresaSubUsuario::where('empresa_id', $empresaId)->with('user')->findOrFail($id);

        $novo = ! $sub->ativo;
        $sub->update(['ativo' => $novo]);
        $sub->user?->update(['active' => $novo]);

        return response()->json(['ativo' => $novo]);
    }

    // DELETE /empresa/sub-usuarios/{id}
    public function destroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);
        $sub = EmpresaSubUsuario::where('empresa_id', $empresaId)->findOrFail($id);

        DB::transaction(function () use ($sub, $empresaId) {
            $userId = $sub->user_id;
            $sub->delete();
            UserContext::where('user_id', $userId)->where('context_id', $empresaId)->where('role', 'empresa')->delete();
            User::where('id', $userId)->delete();
        });

        return response()->noContent();
    }
}
