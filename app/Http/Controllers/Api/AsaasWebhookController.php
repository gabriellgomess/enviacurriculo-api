<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditoCompra;
use App\Models\FranquiaContaReceber;
use App\Models\Parceiro;
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

        $evento    = $request->input('event');
        $paymentId = $request->input('payment.id');

        $eventosRelevantes = ['PAYMENT_CREATED', 'PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'];
        if (!in_array($evento, $eventosRelevantes) || !$paymentId) {
            return response()->json(['message' => 'Evento ignorado.']);
        }

        // 1) Compra de créditos do candidato (cobrança avulsa)
        $compra = CreditoCompra::where('asaas_payment_id', $paymentId)->first();
        if ($compra && in_array($evento, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'])) {
            app(CandidatoCreditoController::class)->confirmarPagamento($compra);
            return response()->json(['message' => 'Processado (crédito candidato).']);
        }

        // 2) Assinatura recorrente de parceiro → gera/atualiza conta a receber
        $subscriptionId = $request->input('payment.subscription');
        if ($subscriptionId) {
            $processado = $this->processarAssinaturaParceiro($request, $evento, $paymentId, $subscriptionId);
            if ($processado) {
                return response()->json(['message' => 'Processado (assinatura parceiro).']);
            }
        }

        Log::warning("Webhook Asaas: pagamento não vinculado (payment_id={$paymentId}, event={$evento})");
        return response()->json(['message' => 'Pagamento não vinculado.'], 404);
    }

    /**
     * Cria a conta a receber da mensalidade do parceiro (na cobrança gerada) e
     * a marca como paga quando o pagamento é confirmado. Idempotente via
     * asaas_payment_id.
     */
    private function processarAssinaturaParceiro(Request $request, string $evento, string $paymentId, string $subscriptionId): bool
    {
        $parceiro = Parceiro::where('asaas_subscription_id', $subscriptionId)->first();
        if (!$parceiro) {
            return false;
        }

        $valor       = round((float) $request->input('payment.value', $parceiro->plano_valor ?? 0), 2);
        $vencimento  = $request->input('payment.dueDate') ?: now()->toDateString();
        $statusConta = in_array($evento, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED']) ? 'pago' : 'pendente';

        $conta = FranquiaContaReceber::firstOrNew(['asaas_payment_id' => $paymentId]);

        $conta->fill([
            'franquia_id'           => null,
            'origem'                => 'parceiro',
            'parceiro_id'           => $parceiro->id,
            'empresa_nome'          => $parceiro->nome_empresa,
            'descricao'             => "Assinatura Parceiro ({$parceiro->plano})",
            'asaas_subscription_id' => $subscriptionId,
            'taxa_servico'          => 0,
            'valor_bruto'           => $valor,
            'valor_liquido'         => $valor,
            'data_faturamento'      => $conta->data_faturamento ?? now()->toDateString(),
            'data_vencimento'       => $vencimento,
        ]);

        // Nunca "rebaixar" de pago para pendente
        if ($conta->status !== 'pago') {
            $conta->status = $statusConta;
        }

        $conta->save();

        return true;
    }
}
