<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\EmpresaNotificacao;
use Illuminate\Http\Request;

class EmpresaNotificacaoController extends Controller
{
    use HasTokenContext;

    /**
     * GET /empresa/notificacoes?per_page=10
     */
    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $notificacoes = EmpresaNotificacao::where('empresa_id', $empresaId)
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('per_page', 10), 50))
            ->get(['id', 'titulo', 'corpo', 'lida', 'created_at']);

        return response()->json(['data' => $notificacoes]);
    }

    /**
     * PATCH /empresa/notificacoes/{id}/lida — marca uma notificação como lida.
     */
    public function marcarLida(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $notificacao = EmpresaNotificacao::where('empresa_id', $empresaId)->findOrFail($id);
        $notificacao->update(['lida' => true]);

        return response()->json(['message' => 'Notificação marcada como lida.']);
    }

    /**
     * POST /empresa/notificacoes/lidas — marca todas como lidas.
     */
    public function marcarLidas(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        EmpresaNotificacao::where('empresa_id', $empresaId)
            ->where('lida', false)
            ->update(['lida' => true]);

        return response()->json(['message' => 'Notificações marcadas como lidas.']);
    }
}
