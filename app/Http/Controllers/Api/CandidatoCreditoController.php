<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CreditoMovimentacao;
use App\Models\CreditoPacote;
use Illuminate\Http\Request;

class CandidatoCreditoController extends Controller
{
    private function candidatoDoUsuario(): Candidato
    {
        return Candidato::where('user_id', auth()->id())->firstOrFail();
    }

    // GET /candidato/creditos/saldo
    public function saldo()
    {
        $c = $this->candidatoDoUsuario();
        return response()->json([
            'data' => ['saldo' => (int) $c->creditos],
        ]);
    }

    // GET /candidato/creditos/extrato
    public function extrato(Request $request)
    {
        $c = $this->candidatoDoUsuario();
        $movs = CreditoMovimentacao::where('candidato_id', $c->id)
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json([
            'data' => $movs->items(),
            'meta' => $movs->toArray(),
        ]);
    }

    // GET /candidato/creditos/pacotes
    public function pacotes()
    {
        $pacotes = CreditoPacote::where('active', true)
            ->orderBy('ordem')
            ->orderBy('quantidade')
            ->get();

        return response()->json(['data' => $pacotes]);
    }

    // POST /candidato/creditos/comprar
    //
    // Stub: a integração com o gateway (Asaas) será implementada
    // em um próximo momento. Por enquanto retorna 501.
    public function comprar(Request $request)
    {
        $request->validate([
            'pacote_id'  => 'nullable|integer|exists:creditos_pacotes,id',
            'quantidade' => 'nullable|integer|min:1',
            'cpf'        => 'nullable|string',
            'nome'       => 'nullable|string',
        ]);

        return response()->json([
            'message' => 'Gateway de pagamento (Asaas) ainda não configurado. Integração em andamento.',
        ], 501);
    }
}
