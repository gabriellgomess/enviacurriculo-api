<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailOptOut;
use Illuminate\Http\Request;

/**
 * Cancelamento de inscrição de e-mails (tela /unsubscribe do home).
 * Contrato documentado em CHANGES.md (Ignacio).
 */
class UnsubscribeController extends Controller
{
    /**
     * GET /unsubscribe?token=TOKEN — valida o token.
     */
    public function show(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $registro = EmailOptOut::where('token', $request->token)->first();

        if (!$registro) {
            return response()->json(['valid' => false, 'reason' => 'invalid_token'], 404);
        }

        if ($registro->unsubscribed_at) {
            return response()->json(['valid' => false, 'reason' => 'already_unsubscribed']);
        }

        return response()->json(['valid' => true]);
    }

    /**
     * POST /unsubscribe — efetiva o descadastro.
     */
    public function store(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $registro = EmailOptOut::where('token', $request->token)->first();

        if (!$registro) {
            return response()->json(['valid' => false, 'reason' => 'invalid_token'], 404);
        }

        if ($registro->unsubscribed_at) {
            return response()->json(['success' => true, 'reason' => 'already_unsubscribed']);
        }

        $registro->update(['unsubscribed_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Inscrição cancelada com sucesso.']);
    }
}
