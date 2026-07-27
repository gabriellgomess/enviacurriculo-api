<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Integração com o gateway de pagamento Asaas (https://www.asaas.com).
 *
 * Integração REAL — exige ASAAS_API_KEY configurada no .env. Não há modo
 * mock: sem a chave, as operações falham explicitamente, em vez de simular
 * um pagamento (o que creditaria o candidato sem recebimento).
 *
 * Configuração: ver ASAAS_SETUP.md em api/.
 */
class AsaasService
{
    private ?string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('services.asaas.api_key');
        $this->baseUrl = rtrim(config('services.asaas.base_url', 'https://api.asaas.com/v3'), '/');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Cria uma cobrança PIX e retorna os dados do QR Code.
     *
     * @param array{nome:string, cpf:string, email:?string, valor:float, descricao:string} $dados
     * @return array{payment_id:string, qr_code:string, qr_code_image:string, expiration_date:string}
     */
    public function criarCobrancaPix(array $dados): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Gateway de pagamento (Asaas) não configurado.');
        }

        $cliente = $this->buscarOuCriarCliente($dados);

        $pagamento = Http::withHeaders(['access_token' => $this->apiKey])
            ->post("{$this->baseUrl}/payments", [
                'customer'    => $cliente['id'],
                'billingType' => 'PIX',
                'value'       => $dados['valor'],
                'dueDate'     => now()->addDay()->toDateString(),
                'description' => $dados['descricao'],
            ])
            ->throw()
            ->json();

        $qrCode = Http::withHeaders(['access_token' => $this->apiKey])
            ->get("{$this->baseUrl}/payments/{$pagamento['id']}/pixQrCode")
            ->throw()
            ->json();

        $imagemBase64 = $qrCode['encodedImage'] ?? '';

        return [
            'payment_id'      => $pagamento['id'],
            'qr_code'         => $qrCode['payload'] ?? '',
            // Asaas devolve o base64 "cru" (sem o prefixo data:); montamos a
            // data URI aqui pra o frontend só usar <img src={qrCodeImage}> direto.
            'qr_code_image'   => $imagemBase64 ? "data:image/png;base64,{$imagemBase64}" : '',
            'expiration_date' => $qrCode['expirationDate'] ?? now()->addHour()->toIso8601String(),
        ];
    }

    /**
     * Consulta o status atual de um pagamento.
     * Retorna um dos status do Asaas: PENDING, RECEIVED, CONFIRMED, OVERDUE, etc.
     *
     * Sem a chave configurada, devolve PENDING — nunca confirma sozinho, para
     * não creditar sem pagamento real.
     *
     * O parâmetro $segundosDesdeACriacao é mantido por compatibilidade com os
     * chamadores existentes e é ignorado na integração real.
     */
    public function consultarStatus(string $paymentId, int $segundosDesdeACriacao = 0): string
    {
        if (!$this->isConfigured()) {
            return 'PENDING';
        }

        $res = Http::withHeaders(['access_token' => $this->apiKey])
            ->get("{$this->baseUrl}/payments/{$paymentId}")
            ->throw()
            ->json();

        return $res['status'] ?? 'PENDING';
    }

    /**
     * Cria uma ASSINATURA recorrente mensal no Asaas e retorna os dados da
     * primeira cobrança (PIX ou Boleto) para exibir no cadastro do parceiro.
     *
     * Integração real — exige ASAAS_API_KEY configurada.
     *
     * @param array{nome:string, cpf:string, email:?string, valor:float, descricao:string, billing_type:string} $dados
     * @return array{
     *   subscription_id:string, customer_id:string, payment_id:?string, billing_type:string,
     *   pix:?array{qr_code:string, qr_code_image:string, expiration_date:string},
     *   boleto:?array{invoice_url:?string, bank_slip_url:?string}
     * }
     */
    public function criarAssinatura(array $dados): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Gateway de pagamento (Asaas) não configurado.');
        }

        $billingType = in_array($dados['billing_type'] ?? 'PIX', ['PIX', 'BOLETO'], true)
            ? $dados['billing_type']
            : 'PIX';

        $cliente = $this->buscarOuCriarCliente($dados);

        $assinatura = Http::withHeaders(['access_token' => $this->apiKey])
            ->post("{$this->baseUrl}/subscriptions", [
                'customer'    => $cliente['id'],
                'billingType' => $billingType,
                'value'       => $dados['valor'],
                'cycle'       => 'MONTHLY',
                'nextDueDate' => now()->toDateString(),
                'description' => $dados['descricao'],
            ])
            ->throw()
            ->json();

        // Primeira cobrança gerada automaticamente pela assinatura
        $pagamentos = Http::withHeaders(['access_token' => $this->apiKey])
            ->get("{$this->baseUrl}/subscriptions/{$assinatura['id']}/payments")
            ->throw()
            ->json();

        $primeiro = $pagamentos['data'][0] ?? null;

        $pix    = null;
        $boleto = null;

        if ($primeiro) {
            if ($billingType === 'PIX') {
                $qrCode = Http::withHeaders(['access_token' => $this->apiKey])
                    ->get("{$this->baseUrl}/payments/{$primeiro['id']}/pixQrCode")
                    ->json();

                $imagemBase64 = $qrCode['encodedImage'] ?? '';
                $pix = [
                    'qr_code'         => $qrCode['payload'] ?? '',
                    'qr_code_image'   => $imagemBase64 ? "data:image/png;base64,{$imagemBase64}" : '',
                    'expiration_date' => $qrCode['expirationDate'] ?? now()->addHour()->toIso8601String(),
                ];
            } else {
                $boleto = [
                    'invoice_url'   => $primeiro['invoiceUrl'] ?? null,
                    'bank_slip_url' => $primeiro['bankSlipUrl'] ?? null,
                ];
            }
        }

        return [
            'subscription_id' => $assinatura['id'],
            'customer_id'     => $cliente['id'],
            'payment_id'      => $primeiro['id'] ?? null,
            'billing_type'    => $billingType,
            'pix'             => $pix,
            'boleto'          => $boleto,
        ];
    }

    private function buscarOuCriarCliente(array $dados): array
    {
        $busca = Http::withHeaders(['access_token' => $this->apiKey])
            ->get("{$this->baseUrl}/customers", ['cpfCnpj' => $dados['cpf']])
            ->throw()
            ->json();

        if (!empty($busca['data'])) {
            return $busca['data'][0];
        }

        return Http::withHeaders(['access_token' => $this->apiKey])
            ->post("{$this->baseUrl}/customers", [
                'name'    => $dados['nome'],
                'cpfCnpj' => $dados['cpf'],
                'email'   => $dados['email'] ?? null,
            ])
            ->throw()
            ->json();
    }
}
