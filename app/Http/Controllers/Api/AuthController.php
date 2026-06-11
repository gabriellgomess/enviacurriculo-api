<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\AdminPermission;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Franquia;
use App\Models\Parceiro;
use App\Models\UserContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Passo 1: valida credenciais e retorna os roles do usuário.
     * Se o usuário tiver apenas um role e não for admin, já retorna o token.
     * Se for admin ou tiver múltiplos contextos em um role, retorna a lista para o frontend exibir o select.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'role'     => 'required|in:admin,empresa,franquia,candidato,parceiro',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $user = Auth::user();

        if (!$user->active) {
            Auth::logout();
            return response()->json(['message' => 'Usuário inativo.'], 403);
        }

        $requestedRole = $request->role;

        // Admin pode acessar qualquer painel
        $isAdmin = $user->hasRole('admin');
        $hasRole = $user->hasRole($requestedRole);

        if (!$isAdmin && !$hasRole) {
            Auth::logout();
            return response()->json(['message' => 'Acesso não autorizado para este painel.'], 403);
        }

        // Painel admin não precisa de contexto
        if ($requestedRole === 'admin') {
            if (!$isAdmin) {
                Auth::logout();
                return response()->json(['message' => 'Acesso não autorizado.'], 403);
            }

            $token = $user->createToken('auth', ['role:admin'])->plainTextToken;

            AccessLog::record($user, 'admin', 'login');

            $perm = AdminPermission::firstOrCreate(
                ['user_id' => $user->id],
                ['acesso_total' => true, 'menus_permitidos' => []]
            );

            return response()->json([
                'token'            => $token,
                'user'             => $user->only('id', 'name', 'email'),
                'role'             => 'admin',
                'context'          => null,
                'acesso_total'     => $perm->acesso_total,
                'menus_permitidos' => $perm->menus_permitidos ?? [],
            ]);
        }

        // Para os demais painéis, busca os contextos disponíveis
        $contextos = $this->getContextos($user, $requestedRole, $isAdmin);

        if ($contextos->isEmpty()) {
            Auth::logout();
            return response()->json(['message' => 'Nenhum contexto disponível para este painel.'], 403);
        }

        // Se tiver apenas um contexto, retorna o token direto
        if ($contextos->count() === 1) {
            return $this->issueToken($user, $requestedRole, $contextos->first());
        }

        // Múltiplos contextos: retorna a lista para o frontend exibir o select
        return response()->json([
            'requires_context' => true,
            'role'             => $requestedRole,
            'contextos'        => $contextos,
        ]);
    }

    /**
     * Passo 2: recebe o contexto escolhido no select e emite o token.
     */
    public function selectContext(Request $request)
    {
        $request->validate([
            'email'      => 'required|email',
            'password'   => 'required|string',
            'role'       => 'required|in:empresa,franquia,candidato,parceiro',
            'context_id' => 'required|integer',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');
        $requestedRole = $request->role;

        if (!$isAdmin && !$user->hasRole($requestedRole)) {
            Auth::logout();
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $contextos = $this->getContextos($user, $requestedRole, $isAdmin);
        $contexto = $contextos->firstWhere('id', $request->context_id);

        if (!$contexto) {
            Auth::logout();
            return response()->json(['message' => 'Contexto inválido.'], 403);
        }

        return $this->issueToken($user, $requestedRole, $contexto, $isAdmin);
    }

    /**
     * Admin: lista contextos de um role sem precisar de senha.
     */
    public function listContextos(Request $request, string $role)
    {
        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Apenas administradores podem listar contextos.'], 403);
        }

        $valid = ['empresa', 'franquia', 'candidato', 'parceiro'];
        if (!in_array($role, $valid)) {
            return response()->json(['message' => 'Role inválido.'], 422);
        }

        $list = $this->getContextos($user, $role, true);
        return response()->json(['data' => $list]);
    }

    /**
     * Admin: assume um contexto sem precisar da senha do usuário alvo.
     */
    public function impersonate(Request $request)
    {
        $request->validate([
            'context_id' => 'required|integer',
            'role'       => 'required|in:empresa,franquia,candidato,parceiro',
        ]);

        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Apenas administradores podem impersonar.'], 403);
        }

        $contextos = $this->getContextos($user, $request->role, true);
        $contexto  = $contextos->firstWhere('id', $request->context_id);

        if (!$contexto) {
            return response()->json(['message' => 'Contexto inválido.'], 403);
        }

        return $this->issueToken($user, $request->role, $contexto, true);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        // Extrai o role das abilities do token (ex.: "role:franquia")
        $roleAbility = collect($token->abilities ?? [])
            ->first(fn($a) => str_starts_with($a, 'role:'));
        $userType = $roleAbility ? substr($roleAbility, 5) : 'desconhecido';

        AccessLog::record($request->user(), $userType, 'logout');

        $token->delete();

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user'    => $user->only('id', 'name', 'email', 'phone'),
            'roles'   => $user->getRoleNames(),
        ]);
    }

    private function getContextos($user, string $role, bool $isAdmin)
    {
        if ($isAdmin) {
            // Admin vê todos os contextos
            return match ($role) {
                'empresa'   => Empresa::where('active', true)->get(['id', 'razao_social as nome']),
                'franquia'  => Franquia::where('active', true)->get(['id', 'nome', 'tipo', 'menus_permitidos']),
                'candidato' => Candidato::with('user:id,name')->where('active', true)->get()->map(fn($c) => ['id' => $c->id, 'nome' => $c->user->name]),
                'parceiro'  => Parceiro::with('user:id,name')->where('active', true)->get()->map(fn($c) => ['id' => $c->id, 'nome' => $c->user->name]),
                default     => collect(),
            };
        }

        // Usuário comum vê apenas seus contextos vinculados
        $contextIds = UserContext::where('user_id', $user->id)
            ->where('role', $role)
            ->pluck('context_id');

        return match ($role) {
            'empresa'   => Empresa::whereIn('id', $contextIds)->where('active', true)->get(['id', 'razao_social as nome']),
            'franquia'  => Franquia::whereIn('id', $contextIds)->where('active', true)->get(['id', 'nome', 'tipo', 'menus_permitidos']),
            'candidato' => Candidato::whereIn('id', $contextIds)->with('user:id,name')->where('active', true)->get()->map(fn($c) => ['id' => $c->id, 'nome' => $c->user->name]),
            'parceiro'  => Parceiro::whereIn('id', $contextIds)->with('user:id,name')->where('active', true)->get()->map(fn($c) => ['id' => $c->id, 'nome' => $c->user->name]),
            default     => collect(),
        };
    }

    private function issueToken($user, string $role, $contexto, bool $isAdmin = false)
    {
        $abilities = ["role:{$role}", "context:{$contexto['id']}"];
        if ($isAdmin) {
            $abilities[] = 'is_admin';
        }

        $token = $user->createToken('auth', $abilities)->plainTextToken;

        AccessLog::record($user, $role, 'login');

        return response()->json([
            'token'      => $token,
            'user'       => $user->only('id', 'name', 'email'),
            'role'       => $role,
            'context'    => $contexto,
            'is_admin'   => $isAdmin,
        ]);
    }
}
