<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use App\Services\GeocodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro público de empresa (tela /cadastro do painel empresa).
 * Contrato documentado em CHANGES.md (Ignacio).
 */
class RegisterEmpresaController extends Controller
{
    public function __invoke(Request $request, GeocodeService $geocode)
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
        ], [
            'cnpj.unique'  => 'Já existe uma empresa cadastrada com este CNPJ.',
            'email.unique' => 'Já existe um usuário cadastrado com este e-mail.',
        ]);

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

        return DB::transaction(function () use ($validated, $coords) {
            $user = User::create([
                'name'     => $validated['nome_empresa'],
                'email'    => $validated['email'],
                'phone'    => $validated['telefone'],
                'password' => Hash::make($validated['senha']),
                'active'   => true,
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
                'status'       => 'pendente', // aprovação pelo admin/franquia
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
                'active'       => true,
            ]);

            UserContext::create([
                'user_id'    => $user->id,
                'role'       => 'empresa',
                'context_id' => $empresa->id,
            ]);

            return response()->json([
                'message' => 'Cadastro realizado com sucesso. Aguarde a aprovação para acessar o painel.',
                'empresa' => ['id' => $empresa->id, 'codigo' => $empresa->codigo, 'status' => $empresa->status],
            ], 201);
        });
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
