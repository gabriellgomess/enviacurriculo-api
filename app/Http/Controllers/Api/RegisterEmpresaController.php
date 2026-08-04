<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\FranquiaLead;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use App\Services\AsaasService;
use App\Services\GeocodeService;
use App\Support\Planos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro público de empresa (tela /cadastro do painel empresa).
 *
 * Fluxo "quero ser empresa":
 *  - Plataforma/Ambos: escolhe plano → paga (assinatura Asaas) → acesso liberado
 *  - Agência: acesso liberado direto, sem cobrança e sem funcionalidades
 *  - Os três geram lead no Comercial (tipo=empresa, produto=tipo_acesso)
 */
class RegisterEmpresaController extends Controller
{
    /**
     * Código da franquia que recebe, por padrão, as empresas dos produtos
     * Agência e Ambos. Espelha o comportamento da aplicação modelo.
     * Se não existir, a empresa é criada sem vínculo (o Admin ajusta depois).
     */
    private const FRANQUIA_PADRAO_CODIGO = 'FR-00001';

    public function __construct(private readonly AsaasService $asaas) {}

    /**
     * Conflitos que fariam o cadastro falhar depois de cobrar.
     * As regras `unique:` enxergam registros soft-deleted, então usamos
     * withTrashed() para a checagem bater exatamente com o store().
     */
    private function conflitosCadastro(string $cnpj, string $email): array
    {
        $errors = [];

        $digits = preg_replace('/\D/', '', $cnpj);
        if ($digits) {
            $emUso = Empresa::withTrashed()->whereRaw(
                "REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') = ?",
                [$digits]
            )->exists();

            if ($emUso) {
                $errors['cnpj'] = ['Já existe uma empresa cadastrada com este CNPJ.'];
            }
        }

        if (User::withTrashed()->where('email', $email)->exists()) {
            $errors['email'] = ['Já existe um usuário cadastrado com este e-mail.'];
        }

        return $errors;
    }

    // POST /empresas/cadastrar/verificar
    public function verificarDisponibilidade(Request $request)
    {
        $data = $request->validate([
            'cnpj'  => 'required|string|max:18',
            'email' => 'required|email|max:255',
        ]);

        $conflitos = $this->conflitosCadastro($data['cnpj'], $data['email']);

        return $conflitos
            ? response()->json(['message' => 'Dados já cadastrados.', 'errors' => $conflitos], 422)
            : response()->json(['message' => 'ok']);
    }

    // POST /empresas/cadastrar/pagamento
    // Assinatura mensal do plano contratado (Plataforma/Ambos).
    public function gerarPagamento(Request $request)
    {
        $data = $request->validate([
            'nome_empresa' => 'required|string|max:255',
            'email'        => 'required|email|max:255',
            'cnpj'         => 'required|string|max:18',
            'plano'        => 'required|in:' . implode(',', Planos::chaves()),
            'billing_type' => 'required|in:PIX,BOLETO',
        ]);

        if ($conflitos = $this->conflitosCadastro($data['cnpj'], $data['email'])) {
            return response()->json(['message' => 'Dados já cadastrados.', 'errors' => $conflitos], 422);
        }

        // Valor vem do catálogo no servidor — nunca do cliente
        $plano = Planos::find($data['plano']);
        $valor = (float) $plano['preco'];

        try {
            $assinatura = $this->asaas->criarAssinatura([
                'nome'         => $data['nome_empresa'],
                'cpf'          => preg_replace('/\D/', '', $data['cnpj']),
                'email'        => $data['email'],
                'valor'        => $valor,
                'descricao'    => "{$plano['nome']} — Envia Currículo",
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
            'valor'           => $valor,
            'pix'             => $assinatura['pix'],
            'boleto'          => $assinatura['boleto'],
        ]);
    }

    // GET /empresas/cadastrar/pagamento/{payment_id}/status
    public function statusPagamento($paymentId)
    {
        try {
            return response()->json(['status' => $this->asaas->consultarStatus($paymentId)]);
        } catch (\Throwable) {
            return response()->json(['status' => 'PENDING']);
        }
    }

    public function store(Request $request, GeocodeService $geocode)
    {
        $validated = $request->validate([
            'nome_empresa' => 'required|string|max:255',
            'cnpj'         => 'required|string|max:18|unique:empresas,cnpj',
            'email'        => 'required|email|max:255|unique:users,email',
            'telefone'     => 'required|string|max:20',
            'tipo_empresa' => 'required|in:matriz,filial',
            'senha'        => ['required', Password::min(6)],
            // Endereço
            'cep'          => 'required|string|max:9',
            'rua'          => 'required|string|max:255',
            'numero'       => 'required|string|max:20',
            'complemento'  => 'nullable|string|max:100',
            'bairro'       => 'required|string|max:100',
            'cidade'       => 'required|string|max:100',
            'estado'       => 'required|string|size:2',
            'descricao'    => 'nullable|string',
            // Produto / plano
            'tipo_acesso'  => 'required|in:plataforma,agencia,ambos',
            'produto'      => 'nullable|string', // redundante com tipo_acesso; ignorado
            'plano'        => 'nullable|in:basico,padrao,premium',
            // Assinatura (obrigatória quando o produto inclui a plataforma)
            'asaas_customer_id'     => 'nullable|string|max:255',
            'asaas_subscription_id' => 'nullable|string|max:255',
            'asaas_payment_id'      => 'nullable|string|max:255',
        ], [
            'cnpj.unique'  => 'Já existe uma empresa cadastrada com este CNPJ.',
            'email.unique' => 'Já existe um usuário cadastrado com este e-mail.',
        ]);

        $pagaPlataforma = $validated['tipo_acesso'] !== 'agencia';

        // Somente Agência entra pendente: quem recruta é a franquia, então o
        // acesso só é liberado quando ela aprovar. Plataforma/Ambos já pagaram
        // e entram aprovados.
        $aprovadaDeImediato = $pagaPlataforma;

        // "Paga → libera o acesso": sem assinatura confirmada não cria a empresa
        if ($pagaPlataforma && empty($validated['asaas_subscription_id'])) {
            return response()->json([
                'message' => 'Pagamento não confirmado para o plano escolhido.',
                'errors'  => ['plano' => ['É necessário concluir o pagamento antes de finalizar o cadastro.']],
            ], 422);
        }

        // Geolocalização (não bloqueia o cadastro em caso de falha)
        $coords = null;
        try {
            $coords = $geocode->geocode(
                $validated['rua'], $validated['numero'],
                $validated['bairro'], $validated['cidade'], $validated['estado'],
            );
        } catch (\Throwable) {
            // segue sem coordenadas
        }

        return DB::transaction(function () use ($validated, $coords, $pagaPlataforma, $aprovadaDeImediato) {
            $user = User::create([
                'name'     => $validated['nome_empresa'],
                'email'    => $validated['email'],
                'phone'    => $validated['telefone'],
                'password' => Hash::make($validated['senha']),
                // Segue o mesmo par status/active usado na aprovação pelo Admin
                'active'   => $aprovadaDeImediato,
            ]);

            UserRole::create(['user_id' => $user->id, 'role' => 'empresa']);

            $empresa = Empresa::create([
                'codigo'       => $this->gerarCodigo(),
                'razao_social' => $validated['nome_empresa'],
                'cnpj'         => $validated['cnpj'],
                'email'        => $validated['email'],
                'telefone'     => $validated['telefone'],
                'tipo_empresa' => $validated['tipo_empresa'],
                'tipo_acesso'  => $validated['tipo_acesso'],
                'plano'        => $validated['plano'] ?? null,
                // Plataforma/Ambos: pago → acesso liberado.
                // Agência: aguarda a franquia responsável liberar.
                'status'       => $aprovadaDeImediato ? 'aprovado' : 'pendente',
                'franquia_id'  => $this->franquiaPadraoId($validated['tipo_acesso']),
                'descricao'    => $validated['descricao'] ?? null,
                'cep'          => $validated['cep'],
                'rua'          => $validated['rua'],
                'numero'       => $validated['numero'],
                'complemento'  => $validated['complemento'] ?? null,
                'bairro'       => $validated['bairro'],
                'cidade'       => $validated['cidade'],
                'estado'       => $validated['estado'],
                'latitude'     => $coords['latitude'] ?? null,
                'longitude'    => $coords['longitude'] ?? null,
                // Prazo de vencimento e reposição são negociados com a franquia
                // e definidos depois pelo Admin no cadastro da empresa — não
                // devem sair já com o padrão de 30 dias da coluna, como se já
                // tivessem sido acertados no autocadastro.
                'prazo_vencimento_dias' => 0,
                'reposicao_dias'        => 0,
                'active'       => $aprovadaDeImediato,
                'asaas_customer_id'     => $validated['asaas_customer_id'] ?? null,
                'asaas_subscription_id' => $validated['asaas_subscription_id'] ?? null,
                'plano_valor'           => $pagaPlataforma
                    ? (Planos::find($validated['plano'] ?? null)['preco'] ?? null)
                    : null,
                'assinatura_status'     => $pagaPlataforma ? 'ativa' : null,
            ]);

            UserContext::create([
                'user_id'    => $user->id,
                'role'       => 'empresa',
                'context_id' => $empresa->id,
            ]);

            // Lead no Comercial do Admin, identificado pelo produto contratado
            FranquiaLead::create([
                'tipo'          => 'empresa',
                'produto'       => $validated['tipo_acesso'],
                'nome_completo' => $validated['nome_empresa'],
                'email'         => $validated['email'],
                'telefone'      => $validated['telefone'],
                'bairro'        => $validated['bairro'],
                'cidade'        => $validated['cidade'],
                'estado'        => $validated['estado'],
                'status'        => 'novo',
                'observacoes'   => 'Cadastro pelo fluxo "quero ser empresa".'
                    . " Produto: {$validated['tipo_acesso']}."
                    . (!empty($validated['plano']) ? " Plano: {$validated['plano']}." : '')
                    . " CNPJ: {$validated['cnpj']}.",
            ]);

            return response()->json([
                'message' => $aprovadaDeImediato
                    ? 'Cadastro realizado com sucesso. Seu acesso já está liberado.'
                    : 'Cadastro realizado. Você foi vinculado a uma franquia e receberá acesso assim que ela liberar.',
                'empresa' => ['id' => $empresa->id, 'codigo' => $empresa->codigo, 'status' => $empresa->status],
            ], 201);
        });
    }

    /**
     * Produtos Agência e Ambos precisam de uma franquia responsável pelo
     * recrutamento — o vínculo é feito no cadastro. Plataforma pura não tem
     * franquia (a própria empresa opera).
     */
    private function franquiaPadraoId(string $tipoAcesso): ?int
    {
        if ($tipoAcesso === 'plataforma') {
            return null;
        }

        return \App\Models\Franquia::where('codigo', self::FRANQUIA_PADRAO_CODIGO)
            ->where('active', true)
            ->value('id');
    }

    private function gerarCodigo(): string
    {
        $ultimo = Empresa::withTrashed()
            ->where('codigo', 'like', 'EM-%')
            ->orderByDesc('id')
            ->value('codigo');

        $numero = $ultimo ? (int) substr($ultimo, 3) + 1 : 1;
        return 'EM-' . str_pad($numero, 4, '0', STR_PAD_LEFT);
    }
}
