<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditoCompra;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe as notificações de pagamento do Asaas (evento "webhook").
 *
 * Só relevante quando ASAAS_API_KEY está configurada de verdade — em modo
 * mock, a confirmação acontece via polling em
 * CandidatoCreditoController::statusCompra(), não por aqui.
 *
 * Configuração necessária no painel do Asaas: ver ASAAS_SETUP.md.
 */
class AsaasWebhookController extends Controller
{
    // POST /webhooks/asaas
    public function handle(Request $request)
    {
        $token = config('services.asaas.webhook_token');
        if ($token && $request->header('asaas-access-token') !== $token) {
            abort(401, 'Token de webhook inválido.');
        }

        $evento = $request->input('event');
        $paymentId = $request->input('payment.id');

        if (!in_array($evento, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED']) || !$paymentId) {
            return response()->json(['message' => 'Evento ignorado.']);
        }

        $compra = CreditoCompra::where('asaas_payment_id', $paymentId)->first();
        if (!$compra) {
            Log::warning("Webhook Asaas: compra não encontrada para payment_id={$paymentId}");
            return response()->json(['message' => 'Compra não encontrada.'], 404);
        }

        app(CandidatoCreditoController::class)->confirmarPagamento($compra);

        return response()->json(['message' => 'Processado.']);
    }
}
