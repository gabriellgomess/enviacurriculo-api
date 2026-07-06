<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CreditoCompra;
use App\Models\CreditoMovimentacao;
use App\Models\CreditoPacote;
use App\Services\AsaasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CandidatoCreditoController extends Controller
{
    public function __construct(private readonly AsaasService $asaas) {}

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
    public function comprar(Request $request)
    {
        $data = $request->validate([
            'pacote_id'  => 'nullable|integer|exists:creditos_pacotes,id',
            'quantidade' => 'required|integer|min:10',
            'cpf'        => 'required|string|min:11',
            'nome'       => 'required|string|min:3',
        ]);

        $candidato = $this->candidatoDoUsuario();
        $cpf       = preg_replace('/\D/', '', $data['cpf']);

        $pacote = $data['pacote_id'] ? CreditoPacote::find($data['pacote_id']) : null;
        $valor  = $pacote?->preco ?? round($data['quantidade'] * 0.99, 2);

        $compra = CreditoCompra::create([
            'candidato_id' => $candidato->id,
            'pacote_id'    => $pacote?->id,
            'quantidade'   => $data['quantidade'],
            'valor'        => $valor,
            'cpf'          => $cpf,
            'nome'         => $data['nome'],
            'status'       => 'pendente',
        ]);

        $pix = $this->asaas->criarCobrancaPix([
            'nome'      => $data['nome'],
            'cpf'       => $cpf,
            'email'     => $request->user()->email,
            'valor'     => $valor,
            'descricao' => "Compra de {$data['quantidade']} créditos — Envia Currículo",
        ]);

        $compra->update([
            'asaas_payment_id' => $pix['payment_id'],
            'qr_code'          => $pix['qr_code'],
            'qr_code_image'    => $pix['qr_code_image'],
            'expiration_date'  => $pix['expiration_date'],
        ]);

        return response()->json([
            'pix' => [
                'qrCode'         => $pix['qr_code'],
                'qrCodeImage'    => $pix['qr_code_image'],
                'expirationDate' => $pix['expiration_date'],
                'paymentId'      => $pix['payment_id'],
                'compraId'       => $compra->id,
            ],
        ], 201);
    }

    // GET /candidato/creditos/compras/{id}/status
    public function statusCompra(Request $request, int $id)
    {
        $candidato = $this->candidatoDoUsuario();
        $compra    = CreditoCompra::where('candidato_id', $candidato->id)->findOrFail($id);

        if ($compra->status === 'pendente') {
            $status = $this->asaas->consultarStatus(
                $compra->asaas_payment_id,
                $compra->created_at->diffInSeconds(now())
            );

            if (in_array($status, ['RECEIVED', 'CONFIRMED'])) {
                $this->confirmarPagamento($compra);
            }
        }

        return response()->json(['data' => ['status' => $compra->fresh()->status]]);
    }

    /**
     * Credita os créditos ao candidato e marca a compra como paga.
     * Chamado tanto pelo polling (statusCompra) quanto pelo webhook do Asaas.
     */
    public function confirmarPagamento(CreditoCompra $compra): void
    {
        if ($compra->status === 'pago') {
            return; // já processado — evita creditar em duplicidade
        }

        DB::transaction(function () use ($compra) {
            $candidato   = Candidato::lockForUpdate()->findOrFail($compra->candidato_id);
            $saldoAntes  = $candidato->creditos;

            $candidato->increment('creditos', $compra->quantidade);

            CreditoMovimentacao::create([
                'candidato_id'    => $candidato->id,
                'tipo'            => 'compra',
                'quantidade'      => $compra->quantidade,
                'saldo_antes'     => $saldoAntes,
                'saldo_depois'    => $saldoAntes + $compra->quantidade,
                'descricao'       => "Compra de {$compra->quantidade} créditos via PIX",
                'referencia_tipo' => CreditoCompra::class,
                'referencia_id'   => $compra->id,
            ]);

            $compra->update(['status' => 'pago', 'paid_at' => now()]);
        });
    }
}
