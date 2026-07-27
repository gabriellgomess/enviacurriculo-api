<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Empresas do produto "Agência de Empregos" (tipo_acesso = agencia) têm acesso
 * ao painel, mas não às funcionalidades da plataforma: elas apenas acompanham
 * o trabalho feito pela franquia.
 *
 * A navegação e as consultas continuam liberadas (GET); qualquer ação de
 * escrita é barrada aqui — o bloqueio não pode viver só no frontend.
 *
 * Uso: ->middleware('empresa.plataforma') nas rotas de recursos da plataforma.
 */
class BloqueiaEmpresaAgencia
{
    use HasTokenContext;

    public function handle(Request $request, Closure $next): Response
    {
        // Somente ações de escrita são restritas
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }

        try {
            $empresaId = $this->tokenContextId($request);
        } catch (\Throwable) {
            return $next($request); // sem contexto de empresa: não é o caso aqui
        }

        $tipoAcesso = Empresa::whereKey($empresaId)->value('tipo_acesso');

        if ($tipoAcesso === 'agencia') {
            return response()->json([
                'message'     => 'Seu produto é a Agência de Empregos: as ações da plataforma '
                    . 'ficam a cargo da franquia responsável. Contrate a Plataforma para liberar este recurso.',
                'upgrade_to'  => 'plataforma',
                'tipo_acesso' => 'agencia',
            ], 403);
        }

        return $next($request);
    }
}
