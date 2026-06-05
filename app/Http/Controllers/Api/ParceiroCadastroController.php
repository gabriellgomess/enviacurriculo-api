<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parceiro;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ParceiroCadastroController extends Controller
{
    // POST /api/parceiro/cadastro/pagamento
    public function gerarPagamento(Request $request)
    {
        $request->validate([
            'nome_empresa' => 'required|string|max:255',
            'email'        => 'required|email|max:255',
            'cnpj'         => 'required|string|max:18',
            'plano'        => 'required|string',
            'valor'        => 'required|numeric',
            'billing_type' => 'required|in:PIX,BOLETO',
        ]);

        // Retorna um ID de pagamento mock e dados simulados do PIX/Boleto
        $paymentId = 'pay_' . uniqid();

        return response()->json([
            'payment_id' => $paymentId,
            'pix' => [
                'qr_code' => '00020126580014br.gov.bcb.pix0136mock-pix-key-em-desenvolvimento0210mock-value5204000053039865802BR5913EnviaCurriculo6009Sao Paulo62070503***6304ABCD',
                'qr_code_image' => 'iVBORw0KGgoAAAANSUhEUgAAAQAAAAEAAQMAAABmvDolAAAABlBMVEUAAAD///+l2Z/dAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAW0lEQVR42mNkYGA4wMDAsIBhAYMFAwsGCoYFDBYMDBksGBgyWDAwZLBgsGBgyGBh+MDAwPCAwYKBIYMFA0MGCwaGDBYMDBksGBgyWDAwZLBgsGBgyGDh+ICBBQMAAP//8OEBW0B4xVEAAAAASUVORK5CYII=', // pixel mock QR
            ],
            'boleto' => [
                'invoice_url' => 'https://sandbox.asaas.com/i/' . $paymentId,
                'bank_slip_url' => 'https://sandbox.asaas.com/b/' . $paymentId,
            ],
        ]);
    }

    // GET /api/parceiro/cadastro/pagamento/{payment_id}/status
    public function statusPagamento($paymentId)
    {
        return response()->json(['status' => 'RECEIVED']);
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
            ]);

            UserContext::create([
                'user_id'    => $user->id,
                'role'       => 'parceiro',
                'context_id' => $parceiro->id,
            ]);

            return response()->json([
                'message' => 'Cadastro realizado com sucesso.',
                'parceiro' => $parceiro,
            ], 201);
        });
    }
}
