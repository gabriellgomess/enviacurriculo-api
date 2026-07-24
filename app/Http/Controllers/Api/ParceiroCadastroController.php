<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FranquiaContaReceber;
use App\Models\Parceiro;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use App\Services\AsaasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ParceiroCadastroController extends Controller
{
    public function __construct(private readonly AsaasService $asaas) {}

    // POST /api/parceiro/cadastro/pagamento
    // Cria a ASSINATURA recorrente mensal no Asaas e devolve a primeira cobrança.
    public function gerarPagamento(Request $request)
    {
        $data = $request->validate([
            'nome_empresa' => 'required|string|max:255',
            'email'        => 'required|email|max:255',
            'cnpj'         => 'required|string|max:18',
            'plano'        => 'required|string',
            'valor'        => 'required|numeric',
            'billing_type' => 'required|in:PIX,BOLETO',
        ]);

        $cnpj = preg_replace('/\D/', '', $data['cnpj']);

        try {
            $assinatura = $this->asaas->criarAssinatura([
                'nome'         => $data['nome_empresa'],
                'cpf'          => $cnpj,
                'email'        => $data['email'],
                'valor'        => (float) $data['valor'],
                'descricao'    => "Assinatura Parceiro ({$data['plano']}) — Envia Currículo",
                'billing_type' => $data['billing_type'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Não foi possível gerar a cobrança: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'payment_id'      => $assinatura['payment_id'],
            'subscription_id' => $assinatura['subscription_id'],
            'customer_id'     => $assinatura['customer_id'],
            'pix'             => $assinatura['pix'],
            'boleto'          => $assinatura['boleto'],
        ]);
    }

    // GET /api/parceiro/cadastro/pagamento/{payment_id}/status
    public function statusPagamento($paymentId)
    {
        try {
            $status = $this->asaas->consultarStatus($paymentId);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'PENDING']);
        }

        return response()->json(['status' => $status]);
    }

    // POST /api/parceiro/cadastro
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome_empresa' => 'required|string|max:255',
            'cnpj'         => 'required|string|max:18|unique:parceiros,cnpj',
            'email'        => 'required|email|max:255|unique:users,email',
            'telefone'     => 'required|string|max:20',
            'cep'          => 'required|string|max:9',
            'logradouro'   => 'required|string|max:255',
            'numero'       => 'required|string|max:20',
            'complemento'  => 'nullable|string|max:255',
            'bairro'       => 'required|string|max:100',
            'cidade'       => 'required|string|max:100',
            'estado'       => 'required|string|size:2',
            'descricao'    => 'nullable|string',
            'senha'        => 'required|string|min:6',
            'logo'         => 'nullable|image|max:2048',
            'plano'                 => 'nullable|string|max:30',
            'valor'                 => 'nullable|numeric',
            'asaas_customer_id'     => 'nullable|string|max:255',
            'asaas_subscription_id' => 'nullable|string|max:255',
            'asaas_payment_id'      => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $user = User::create([
                'name'     => $validated['nome_empresa'],
                'email'    => $validated['email'],
                'phone'    => $validated['telefone'],
                'password' => Hash::make($validated['senha']),
                'active'   => true,
            ]);

            UserRole::create(['user_id' => $user->id, 'role' => 'parceiro']);

            $logoUrl = null;
            if ($request->hasFile('logo')) {
                $path = $request->file('logo')->store('parceiros/logos', 'public');
                $logoUrl = Storage::disk('public')->url($path);
            }

            $parceiro = Parceiro::create([
                'user_id'      => $user->id,
                'franquia_id'  => null, // Cadastro próprio -> fica em branco
                'nome_empresa' => $validated['nome_empresa'],
                'cnpj'         => $validated['cnpj'],
                'email'        => $validated['email'],
                'telefone'     => $validated['telefone'],
                'cep'          => $validated['cep'],
                'rua'          => $validated['logradouro'],
                'numero'       => $validated['numero'],
                'complemento'  => $validated['complemento'] ?? null,
                'bairro'       => $validated['bairro'],
                'cidade'       => $validated['cidade'],
                'estado'       => $validated['estado'],
                'descricao'    => $validated['descricao'] ?? null,
                'logo_url'     => $logoUrl,
                'active'       => true,
                'plano'                 => $validated['plano'] ?? null,
                'plano_valor'           => $validated['valor'] ?? null,
                'asaas_customer_id'     => $validated['asaas_customer_id'] ?? null,
                'asaas_subscription_id' => $validated['asaas_subscription_id'] ?? null,
                'assinatura_status'     => 'ativa',
            ]);

            UserContext::create([
                'user_id'    => $user->id,
                'role'       => 'parceiro',
                'context_id' => $parceiro->id,
            ]);

            // Primeira cobrança já confirmada no cadastro → gera conta a receber (paga)
            if (!empty($validated['valor'])) {
                $valor = round((float) $validated['valor'], 2);

                FranquiaContaReceber::firstOrCreate(
                    ['asaas_payment_id' => $validated['asaas_payment_id'] ?? ('cad_' . $parceiro->id)],
                    [
                        'franquia_id'           => null,
                        'origem'                => 'parceiro',
                        'parceiro_id'           => $parceiro->id,
                        'empresa_nome'          => $parceiro->nome_empresa,
                        'descricao'             => "Assinatura Parceiro ({$parceiro->plano})",
                        'asaas_subscription_id' => $validated['asaas_subscription_id'] ?? null,
                        'taxa_servico'          => 0,
                        'valor_bruto'           => $valor,
                        'valor_liquido'         => $valor,
                        'data_faturamento'      => now()->toDateString(),
                        'data_vencimento'       => now()->toDateString(),
                        'status'                => 'pago',
                    ]
                );
            }

            return response()->json([
                'message' => 'Cadastro realizado com sucesso.',
                'parceiro' => $parceiro,
            ], 201);
        });
    }
}
