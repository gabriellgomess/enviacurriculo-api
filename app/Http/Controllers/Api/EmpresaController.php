<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BeneficioCatalogo;
use App\Models\Empresa;
use App\Models\EmpresaFollowup;
use App\Models\EmpresaTaxaServico;
use App\Models\NivelVaga;
use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmpresaController extends Controller
{
    public function index(Request $request)
    {
        $query = Empresa::with('franquia:id,codigo,nome');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('razao_social', 'like', "%{$s}%")
                  ->orWhere('nome_fantasia', 'like', "%{$s}%")
                  ->orWhere('cnpj', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%")
                  ->orWhere('codigo', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('tipo_acesso')) {
            $query->where('tipo_acesso', $request->tipo_acesso);
        }

        if ($request->filled('franquia_id')) {
            $query->where('franquia_id', $request->franquia_id);
        }

        $empresas = $query->orderBy('razao_social')->paginate(20);

        $meta = [
            'total'     => Empresa::count(),
            'aprovadas' => Empresa::where('status', 'aprovado')->count(),
            'pendentes' => Empresa::where('status', 'pendente')->count(),
            'rejeitadas'=> Empresa::where('status', 'rejeitado')->count(),
        ];

        return response()->json([
            'data' => $empresas->items(),
            'meta' => array_merge($empresas->toArray(), $meta),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'razao_social'          => 'required|string|max:255',
            'nome_fantasia'         => 'nullable|string|max:255',
            'cnpj'                  => 'nullable|string|max:18|unique:empresas,cnpj',
            'email'                 => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'telefone'              => 'nullable|string|max:20',
            'tipo_empresa'          => 'required|in:matriz,filial',
            'tipo_acesso'           => 'nullable|in:plataforma,agencia,ambos',
            'plano'                 => 'nullable|in:basico,padrao,premium',
            'status'                => 'nullable|in:pendente,aprovado,rejeitado',
            'descricao'             => 'nullable|string',
            'cep'                   => 'nullable|string|max:9',
            'rua'                   => 'nullable|string|max:255',
            'numero'                => 'nullable|string|max:20',
            'complemento'           => 'nullable|string|max:100',
            'bairro'                => 'nullable|string|max:100',
            'cidade'                => 'nullable|string|max:100',
            'estado'                => 'nullable|string|size:2',
            'prazo_vencimento_dias' => 'nullable|integer|min:1',
            'reposicao_dias'        => 'nullable|integer|min:1',
            'franquia_id'           => 'nullable|exists:franquias,id',
            'password'              => 'nullable|string|min:6',
            'logo'                  => 'nullable|image|max:2048',
            'beneficios'            => 'nullable|array',
            'beneficios.*'          => 'integer|exists:beneficios_catalogo,id',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $logoUrl = null;
            if ($request->hasFile('logo')) {
                $path    = $request->file('logo')->store('empresas/logos', 'public');
                $logoUrl = Storage::disk('public')->url($path);
            }

            $empresa = Empresa::create(array_merge(
                collect($validated)->except(['password', 'beneficios', 'logo'])->toArray(),
                [
                    'codigo'   => $this->gerarCodigo(),
                    'status'   => $validated['status'] ?? 'aprovado',
                    'active'   => true,
                    'logo_url' => $logoUrl,
                ]
            ));

            if (!empty($validated['beneficios'])) {
                $empresa->beneficios()->sync($validated['beneficios']);
            }

            if (!empty($validated['email']) && !empty($validated['password'])) {
                $user = User::create([
                    'name'     => $validated['razao_social'],
                    'email'    => $validated['email'],
                    'phone'    => $validated['telefone'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'active'   => true,
                ]);
                UserRole::create(['user_id' => $user->id, 'role' => 'empresa']);
                UserContext::create([
                    'user_id'    => $user->id,
                    'role'       => 'empresa',
                    'context_id' => $empresa->id,
                ]);
            }

            return response()->json($empresa->load(['franquia:id,codigo,nome', 'beneficios']), 201);
        });
    }

    public function show(Empresa $empresa)
    {
        $user = $empresa->user();

        return response()->json([
            ...$empresa->load(['franquia:id,codigo,nome', 'followups', 'taxasServico', 'beneficios'])->toArray(),
            'login_email' => $user?->email,
        ]);
    }

    public function update(Request $request, Empresa $empresa)
    {
        $validated = $request->validate([
            'razao_social'          => 'required|string|max:255',
            'nome_fantasia'         => 'nullable|string|max:255',
            'cnpj'                  => 'nullable|string|max:18|unique:empresas,cnpj,' . $empresa->id,
            'email'                 => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($empresa->user()?->id)],
            'telefone'              => 'nullable|string|max:20',
            'tipo_empresa'          => 'required|in:matriz,filial',
            'tipo_acesso'           => 'nullable|in:plataforma,agencia,ambos',
            'plano'                 => 'nullable|in:basico,padrao,premium',
            'status'                => 'nullable|in:pendente,aprovado,rejeitado',
            'descricao'             => 'nullable|string',
            'cep'                   => 'nullable|string|max:9',
            'rua'                   => 'nullable|string|max:255',
            'numero'                => 'nullable|string|max:20',
            'complemento'           => 'nullable|string|max:100',
            'bairro'                => 'nullable|string|max:100',
            'cidade'                => 'nullable|string|max:100',
            'estado'                => 'nullable|string|size:2',
            'prazo_vencimento_dias' => 'nullable|integer|min:1',
            'reposicao_dias'        => 'nullable|integer|min:1',
            'franquia_id'           => 'nullable|exists:franquias,id',
            'password'              => 'nullable|string|min:6',
            'logo'                  => 'nullable|image|max:2048',
            'beneficios'            => 'nullable|array',
            'beneficios.*'          => 'integer|exists:beneficios_catalogo,id',
        ]);

        return DB::transaction(function () use ($validated, $request, $empresa) {
            $password  = $validated['password'] ?? null;
            $beneficios = $validated['beneficios'] ?? null;
            $fields    = collect($validated)->except(['password', 'beneficios', 'logo'])->toArray();

            if ($request->hasFile('logo')) {
                // remove logo antiga
                if ($empresa->logo_url) {
                    $oldPath = str_replace(Storage::disk('public')->url(''), '', $empresa->logo_url);
                    Storage::disk('public')->delete($oldPath);
                }
                $path = $request->file('logo')->store('empresas/logos', 'public');
                $fields['logo_url'] = Storage::disk('public')->url($path);
            }

            $empresa->update($fields);

            if ($beneficios !== null) {
                $empresa->beneficios()->sync($beneficios);
            }

            $user = $empresa->user();
            if ($user) {
                $userUpdate = [
                    'name'   => $validated['razao_social'],
                    'email'  => $validated['email'] ?? $user->email,
                    'phone'  => $validated['telefone'] ?? $user->phone,
                    'active' => ($validated['status'] ?? 'aprovado') === 'aprovado',
                ];
                if ($password) $userUpdate['password'] = Hash::make($password);
                $user->update($userUpdate);
            } elseif (!empty($validated['email']) && $password) {
                $newUser = User::create([
                    'name'     => $validated['razao_social'],
                    'email'    => $validated['email'],
                    'phone'    => $validated['telefone'] ?? null,
                    'password' => Hash::make($password),
                    'active'   => true,
                ]);
                UserRole::create(['user_id' => $newUser->id, 'role' => 'empresa']);
                UserContext::create([
                    'user_id'    => $newUser->id,
                    'role'       => 'empresa',
                    'context_id' => $empresa->id,
                ]);
            }

            return response()->json($empresa->fresh()->load(['franquia:id,codigo,nome', 'beneficios']));
        });
    }

    public function destroy(Empresa $empresa)
    {
        $empresa->delete();
        return response()->json(['message' => 'Empresa removida com sucesso.']);
    }

    public function changeStatus(Request $request, Empresa $empresa)
    {
        $request->validate(['status' => 'required|in:pendente,aprovado,rejeitado']);

        $empresa->update([
            'status' => $request->status,
            'active' => $request->status === 'aprovado',
        ]);

        $user = $empresa->user();
        $user?->update(['active' => $request->status === 'aprovado']);

        return response()->json($empresa->fresh());
    }

    // ── Benefícios ──────────────────────────────────────────────

    public function beneficiosCatalogo()
    {
        return response()->json(
            BeneficioCatalogo::orderBy('categoria')->orderBy('nome')->get()
        );
    }

    public function indexBeneficios(Empresa $empresa)
    {
        return response()->json($empresa->beneficios()->get());
    }

    public function syncBeneficios(Request $request, Empresa $empresa)
    {
        $request->validate([
            'beneficios'   => 'required|array',
            'beneficios.*' => 'integer|exists:beneficios_catalogo,id',
        ]);

        $empresa->beneficios()->sync($request->beneficios);
        return response()->json($empresa->beneficios()->get());
    }

    // ── Follow-ups ──────────────────────────────────────────────

    public function storeFollowup(Request $request, Empresa $empresa)
    {
        $request->validate(['mensagem' => 'required|string']);

        $followup = EmpresaFollowup::create([
            'empresa_id' => $empresa->id,
            'user_id'    => auth()->id(),
            'user_name'  => auth()->user()->name,
            'user_type'  => 'admin',
            'mensagem'   => $request->mensagem,
        ]);

        return response()->json($followup, 201);
    }

    public function updateFollowup(Request $request, Empresa $empresa, EmpresaFollowup $followup)
    {
        $request->validate(['mensagem' => 'required|string']);
        $followup->update(['mensagem' => $request->mensagem]);
        return response()->json($followup);
    }

    public function destroyFollowup(Empresa $empresa, EmpresaFollowup $followup)
    {
        $followup->delete();
        return response()->json(['message' => 'Follow-up removido.']);
    }

    // ── Taxas de Serviço ────────────────────────────────────────

    public function indexTaxas(Empresa $empresa)
    {
        return response()->json($empresa->taxasServico()->with('nivelVaga')->get());
    }

    public function upsertTaxa(Request $request, Empresa $empresa)
    {
        $request->validate([
            'nivel_vaga_id' => 'required|exists:niveis_vagas,id',
            'percentual'    => 'required|numeric|min:0|max:100',
        ]);

        $taxa = EmpresaTaxaServico::updateOrCreate(
            ['empresa_id' => $empresa->id, 'nivel_vaga_id' => $request->nivel_vaga_id],
            ['percentual' => $request->percentual]
        );

        return response()->json($taxa->load('nivelVaga'), 201);
    }

    public function destroyTaxa(Empresa $empresa, EmpresaTaxaServico $taxa)
    {
        $taxa->delete();
        return response()->json(['message' => 'Taxa removida.']);
    }

    // ── Níveis de Vagas ─────────────────────────────────────────

    public function niveisVagas()
    {
        return response()->json(NivelVaga::orderBy('ordem')->orderBy('nome')->get());
    }

    public function relatorios(Request $request)
    {
        $query = Empresa::withCount([
            'vagas as vagas_abertas_count' => function ($q) {
                $q->where('status', 'aberta');
            },
            'vagas as vagas_fechadas_count' => function ($q) {
                $q->where('status', 'fechada');
            }
        ])->with('franquia:id,nome');

        if ($request->filled('busca')) {
            $s = $request->busca;
            $query->where(function ($q) use ($s) {
                $q->where('razao_social', 'like', "%{$s}%")
                  ->orWhere('nome_fantasia', 'like', "%{$s}%")
                  ->orWhere('cnpj', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('plano')) {
            $query->where('plano', $request->plano);
        }

        $empresas = $query->orderBy('razao_social')->get();

        $data = $empresas->map(function ($e) {
            return [
                'Razão Social'   => $e->razao_social,
                'Nome Fantasia'  => $e->nome_fantasia ?? '—',
                'CNPJ'           => $e->cnpj ?? '—',
                'Franquia'       => $e->franquia?->nome ?? 'Direta / Sem Franquia',
                'Plano'          => ucfirst($e->plano ?? 'básico'),
                'Status'         => ucfirst($e->status ?? 'pendente'),
                'Cidade/UF'      => ($e->cidade && $e->estado) ? "{$e->cidade}/{$e->estado}" : '—',
                'Vagas Abertas'  => $e->vagas_abertas_count,
                'Vagas Fechadas' => $e->vagas_fechadas_count,
            ];
        });

        return response()->json(['data' => $data]);
    }

    // ── Helpers ─────────────────────────────────────────────────

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
