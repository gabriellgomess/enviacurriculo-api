<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Integração com o gateway de pagamento Asaas (https://www.asaas.com).
 *
 * Enquanto ASAAS_API_KEY não estiver configurada no .env, todos os métodos
 * operam em modo MOCK: geram um PIX fake e confirmam o pagamento sozinhos
 * alguns segundos depois (ver consultarStatus()). Isso permite validar a
 * tela de "Minha Carteira" ponta a ponta sem uma conta Asaas real.
 *
 * Para ativar a integração de verdade, ver ASAAS_SETUP.md na raiz do projeto.
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
     * @return array{payment_id:string, qr_code:string, qr_code_image:string, expiration_date:string, mock:bool}
     */
    public function criarCobrancaPix(array $dados): array
    {
        if (!$this->isConfigured()) {
            return $this->mockCobrancaPix($dados);
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
            // data URI aqui pra o frontend só usar <img src={qrCodeImage}>
            // direto, sem precisar saber se veio do modo mock ou real.
            'qr_code_image'   => $imagemBase64 ? "data:image/png;base64,{$imagemBase64}" : '',
            'expiration_date' => $qrCode['expirationDate'] ?? now()->addHour()->toIso8601String(),
            'mock'            => false,
        ];
    }

    /**
     * Consulta o status atual de um pagamento.
     * Retorna um dos status do Asaas: PENDING, RECEIVED, CONFIRMED, OVERDUE, etc.
     *
     * Em modo mock, confirma automaticamente passados ~8s da criação — o
     * chamador (CandidatoCreditoController::statusCompra) informa esse tempo
     * decorrido via $segundosDesdeACriacao.
     */
    public function consultarStatus(string $paymentId, int $segundosDesdeACriacao = 0): string
    {
        if (!$this->isConfigured()) {
            return $segundosDesdeACriacao >= 8 ? 'CONFIRMED' : 'PENDING';
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

    private function mockCobrancaPix(array $dados): array
    {
        $payload = '00020126580014BR.GOV.BCB.PIX0136mock-pix-key-em-desenvolvimento52040000530398654'
            . str_pad((string) round($dados['valor'] * 100), 4, '0', STR_PAD_LEFT)
            . '5802BR5913EnviaCurriculo6009Sao Paulo62070503***6304MOCK';

        return [
            'payment_id'      => 'mock_' . Str::random(24),
            'qr_code'         => $payload,
            'qr_code_image'   => $this->gerarImagemQrMock($payload),
            'expiration_date' => now()->addHour()->toIso8601String(),
            'mock'            => true,
        ];
    }

    /**
     * Gera um SVG (data URI) com um padrão visual parecido com um QR Code,
     * só para representar visualmente o PIX de teste — não é um QR Code
     * real/escaneável. Determinístico a partir do payload, então o mesmo
     * PIX sempre gera o mesmo desenho.
     *
     * Evitamos gerar um PNG na mão (fácil de corromper) e não dependemos da
     * extensão GD (nem sempre disponível) — SVG é só texto, sempre válido.
     */
    private function gerarImagemQrMock(string $payload): string
    {
        $modulos = 21; // mesmo tamanho de um QR versão 1
        $tamanhoModulo = 10;
        $lado = $modulos * $tamanhoModulo;

        $seed = crc32($payload);
        $bit = fn (int $x, int $y): bool => (bool) (crc32("{$seed}:{$x}:{$y}") & 1);

        $finder = function (int $ox, int $oy) use ($tamanhoModulo): array {
            // anel externo preto 7x7, miolo branco 5x5, centro preto 3x3
            return [
                ['x' => $ox, 'y' => $oy, 'w' => 7 * $tamanhoModulo, 'h' => 7 * $tamanhoModulo, 'fill' => '#000'],
                ['x' => $ox + $tamanhoModulo, 'y' => $oy + $tamanhoModulo, 'w' => 5 * $tamanhoModulo, 'h' => 5 * $tamanhoModulo, 'fill' => '#fff'],
                ['x' => $ox + 2 * $tamanhoModulo, 'y' => $oy + 2 * $tamanhoModulo, 'w' => 3 * $tamanhoModulo, 'h' => 3 * $tamanhoModulo, 'fill' => '#000'],
            ];
        };

        $rects = [];
        for ($x = 0; $x < $modulos; $x++) {
            for ($y = 0; $y < $modulos; $y++) {
                $dentroDeUmFinder = ($x < 8 && $y < 8) || ($x >= $modulos - 8 && $y < 8) || ($x < 8 && $y >= $modulos - 8);
                if ($dentroDeUmFinder) {
                    continue;
                }
                if ($bit($x, $y)) {
                    $rects[] = ['x' => $x * $tamanhoModulo, 'y' => $y * $tamanhoModulo, 'w' => $tamanhoModulo, 'h' => $tamanhoModulo, 'fill' => '#000'];
                }
            }
        }

        $rects = [
            ...$rects,
            ...$finder(0, 0),
            ...$finder(($modulos - 7) * $tamanhoModulo, 0),
            ...$finder(0, ($modulos - 7) * $tamanhoModulo),
        ];

        $svgRects = implode('', array_map(
            fn($r) => "<rect x=\"{$r['x']}\" y=\"{$r['y']}\" width=\"{$r['w']}\" height=\"{$r['h']}\" fill=\"{$r['fill']}\"/>",
            $rects
        ));

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$lado}\" height=\"{$lado}\" viewBox=\"0 0 {$lado} {$lado}\">"
            . "<rect width=\"{$lado}\" height=\"{$lado}\" fill=\"#fff\"/>"
            . $svgRects
            . "</svg>";

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
