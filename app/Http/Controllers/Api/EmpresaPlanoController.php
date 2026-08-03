<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\FranquiaContaReceber;
use App\Services\AsaasService;
use App\Support\Planos;
use Illuminate\Http\Request;

class EmpresaPlanoController extends Controller
{
    use HasTokenContext;

    public function __construct(private readonly AsaasService $asaas) {}

    // GET /empresa/plano
    public function show(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        $chave = $empresa->plano;
        $plano = Planos::find($chave);

        return response()->json(['data' => [
            'chave'                  => $chave,
            'plano_chave'            => $chave,
            'ativo'                  => (bool) $empresa->active,
            'vence_em'               => null,
            'tipo_acesso'            => $empresa->tipo_acesso,
            'status'                 => $empresa->status,
            'permite_publicar_vagas' => Planos::permitePublicarVagas($chave),
            'permite_receber_feed'   => Planos::permiteReceberFeed($chave),
            'plano'                  => $plano ? [
                'nome'    => $plano['nome'],
                'preco'   => $plano['preco'],
                'recursos'=> $plano['recursos'],
            ] : null,
        ]]);
    }

    // GET /empresa/plano/catalogo
    public function catalogo()
    {
        return response()->json(['data' => Planos::all()]);
    }

    // POST /empresa/plano/upgrade
    // Só troca o plano direto quando ele não tem custo. Planos pagos exigem o
    // fluxo de pagamento (gerarPagamento/statusPagamento) antes de aplicar.
    public function upgrade(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        $data = $request->validate([
            'plano' => 'required|in:' . implode(',', Planos::chaves()),
        ]);

        $plano = Planos::find($data['plano']);
        if (($plano['preco'] ?? 0) > 0) {
            return response()->json([
                'message' => 'Este plano exige pagamento. Gere a cobrança antes de contratar.',
            ], 422);
        }

        $empresa->update(['plano' => $data['plano']]);

        return response()->json([
            'message' => 'Plano atualizado.',
            'data'    => ['chave' => $empresa->plano],
        ]);
    }

    // POST /empresa/plano/upgrade/pagamento
    // Gera a assinatura/cobrança (PIX ou Boleto) no Asaas para o novo plano.
    // O plano só é trocado quando o pagamento é confirmado (statusPagamento).
    public function gerarPagamento(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        $data = $request->validate([
            'plano'        => 'required|in:' . implode(',', Planos::chaves()),
            'billing_type' => 'required|in:PIX,BOLETO',
        ]);

        // Valor vem do catálogo no servidor — nunca do cliente
        $plano = Planos::find($data['plano']);
        $valor = (float) ($plano['preco'] ?? 0);

        if ($valor <= 0) {
            return response()->json(['message' => 'Este plano não exige pagamento.'], 422);
        }

        try {
            $assinatura = $this->asaas->criarAssinatura([
                'nome'         => $empresa->nome_fantasia ?? $empresa->razao_social,
                'cpf'          => preg_replace('/\D/', '', $empresa->cnpj ?? ''),
                'email'        => $empresa->email,
                'valor'        => $valor,
                'descricao'    => "{$plano['nome']} — Envia Currículo",
                'billing_type' => $data['billing_type'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Não foi possível gerar a cobrança: ' . $e->getMessage(),
            ], 502);
        }

        $empresa->update([
            'asaas_customer_id'     => $assinatura['customer_id'],
            'asaas_subscription_id' => $assinatura['subscription_id'],
            'plano_valor'           => $valor,
        ]);

        return response()->json([
            'payment_id'      => $assinatura['payment_id'],
            'subscription_id' => $assinatura['subscription_id'],
            'customer_id'     => $assinatura['customer_id'],
            'plano'           => $data['plano'],
            'valor'           => $valor,
            'pix'             => $assinatura['pix'],
            'boleto'          => $assinatura['boleto'],
        ]);
    }

    // GET /empresa/plano/upgrade/pagamento/{payment_id}/status?plano=xxx
    // Ao confirmar, aplica o novo plano (o webhook do Asaas cuida do
    // faturamento/conta a receber; aqui só garantimos a troca do plano mesmo
    // que o webhook ainda não tenha chegado).
    public function statusPagamento(Request $request, string $paymentId)
    {
        $empresaId = $this->tokenContextId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        try {
            $status = $this->asaas->consultarStatus($paymentId);
        } catch (\Throwable) {
            $status = 'PENDING';
        }

        if (in_array($status, ['RECEIVED', 'CONFIRMED'], true)) {
            $plano = $request->query('plano');
            if ($plano && in_array($plano, Planos::chaves(), true)) {
                $empresa->update(['plano' => $plano, 'assinatura_status' => 'ativa']);
            }
        }

        return response()->json(['status' => $status]);
    }

    // GET /empresa/faturamentos
    public function faturamentos(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $faturamentos = FranquiaContaReceber::where('origem', 'empresa')
            ->where('empresa_id', $empresaId)
            ->orderByDesc('data_vencimento')
            ->get()
            ->map(fn ($f) => [
                'id'         => $f->id,
                'referencia' => $f->descricao,
                'valor'      => $f->valor_liquido,
                'status'     => $f->status,
                'vencimento' => $f->data_vencimento,
            ]);

        return response()->json(['data' => $faturamentos]);
    }
}
