<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\FranquiaNotificacao;
use Illuminate\Http\Request;

class FranquiaNotificacaoController extends Controller
{
    use HasTokenContext;

    /**
     * GET /franquia/notificacoes?per_page=10
     */
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $notificacoes = FranquiaNotificacao::where('franquia_id', $franquiaId)
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('per_page', 10), 50))
            ->get(['id', 'titulo', 'corpo', 'lida', 'created_at']);

        return response()->json(['data' => $notificacoes]);
    }

    /**
     * POST /franquia/notificacoes/lidas — marca todas como lidas.
     */
    public function marcarLidas(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        FranquiaNotificacao::where('franquia_id', $franquiaId)
            ->where('lida', false)
            ->update(['lida' => true]);

        return response()->json(['message' => 'Notificações marcadas como lidas.']);
    }
}
