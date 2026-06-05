<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait HasTokenContext
{
    /**
     * Extrai o context_id codificado nas abilities do token Sanctum.
     * O token é emitido em AuthController::issueToken com ['role:{role}', 'context:{id}'].
     *
     * Aborta com 403 se o token não tiver contexto (admin não impersonado, p.ex.).
     */
    protected function tokenContextId(Request $request): int
    {
        $token = $request->user()?->currentAccessToken();
        if (!$token) {
            throw new HttpException(401, 'Token inválido.');
        }

        foreach ($token->abilities as $ability) {
            if (str_starts_with($ability, 'context:')) {
                return (int) substr($ability, 8);
            }
        }

        throw new HttpException(403, 'Contexto não definido para esta sessão. Faça login no painel correto.');
    }
}
