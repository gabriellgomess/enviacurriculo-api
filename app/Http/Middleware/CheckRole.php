<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Verifica se o token do usuário possui a ability do role exigido.
     * Admin sempre tem acesso a qualquer painel.
     *
     * Uso nas rotas: middleware('role:empresa')
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $token = $user->currentAccessToken();

        $isAdmin = $token->can('role:admin') || $token->can('is_admin');
        $hasRole = $token->can("role:{$role}");

        if (!$isAdmin && !$hasRole) {
            return response()->json(['message' => 'Acesso não autorizado para este painel.'], 403);
        }

        // Admin sem impersonação (token sem context:{id}) não pode acessar painéis
        // de outros roles. Deve selecionar um contexto via /auth/impersonate primeiro.
        if ($isAdmin && !$hasRole) {
            $hasContext = collect($token->abilities)
                ->contains(fn(string $a) => str_starts_with($a, 'context:'));

            if (!$hasContext) {
                return response()->json([
                    'message'           => 'Selecione um contexto antes de acessar este painel.',
                    'requires_context'  => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
