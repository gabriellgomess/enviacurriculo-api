<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\GeocodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmpresaPerfilController extends Controller
{
    use HasTokenContext;

    public function __construct(private readonly GeocodeService $geocoder) {}

    // GET /empresa/perfil
    public function show(Request $request)
    {
        $empresa = Empresa::with('franquia:id,nome,codigo')
            ->findOrFail($this->tokenContextId($request));

        return response()->json([
            'data' => $this->present($empresa),
        ]);
    }

    // PUT /empresa/perfil
    public function update(Request $request)
    {
        $empresa = Empresa::findOrFail($this->tokenContextId($request));

        $validated = $request->validate([
            'razao_social'          => 'sometimes|required|string|max:255',
            'nome_fantasia'         => 'nullable|string|max:255',
            'cnpj'                  => 'nullable|string|max:18',
            'email'                 => 'nullable|email|max:255',
            'telefone'              => 'nullable|string|max:20',
            'descricao'             => 'nullable|string',
            'cep'                   => 'nullable|string|max:9',
            'rua'                   => 'nullable|string|max:255',
            'logradouro'            => 'nullable|string|max:255',
            'numero'                => 'nullable|string|max:20',
            'complemento'           => 'nullable|string|max:100',
            'bairro'                => 'nullable|string|max:100',
            'cidade'                => 'nullable|string|max:100',
            'estado'                => 'nullable|string|size:2',
        ]);

        // Frontend usa "logradouro"; nosso DB usa "rua"
        if (isset($validated['logradouro'])) {
            $validated['rua'] = $validated['logradouro'];
            unset($validated['logradouro']);
        }

        // Re-geocoda se endereço mudou
        $enderecoMudou = collect(['cep','rua','numero','bairro','cidade','estado'])
            ->some(fn($f) => array_key_exists($f, $validated) && $validated[$f] !== $empresa->{$f});

        $empresa->update($validated);

        if ($enderecoMudou) {
            $coords = $this->geocoder->geocode(
                $empresa->rua, $empresa->numero, $empresa->bairro, $empresa->cidade, $empresa->estado
            );
            if ($coords) {
                $empresa->update($coords);
            }
        }

        return response()->json([
            'message' => 'Perfil atualizado.',
            'empresa' => $this->present($empresa->fresh('franquia:id,nome,codigo')),
        ]);
    }

    // POST /empresa/perfil/logo
    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => 'required|image|max:2048']);
        $empresa = Empresa::findOrFail($this->tokenContextId($request));

        // Remove logo anterior (suporta caminho relativo ou URL legada absoluta)
        if ($empresa->logo_url) {
            $old = $empresa->logo_url;
            // Se for URL absoluta legada, extrai o caminho relativo
            if (str_starts_with($old, 'http')) {
                $old = ltrim(parse_url($old, PHP_URL_PATH), '/');
                $old = preg_replace('#^storage/#', '', $old);
            }
            Storage::disk('public')->delete($old);
        }

        // Grava apenas o caminho relativo — URL computada no present()
        $path = $request->file('logo')->store('empresas/logos', 'public');
        $empresa->update(['logo_url' => $path]);

        return response()->json(['logo_url' => Storage::disk('public')->url($path)]);
    }

    private function present(Empresa $e): array
    {
        return [
            'id'               => $e->id,
            'codigo'           => $e->codigo,
            'razao_social'     => $e->razao_social,
            'nome_fantasia'    => $e->nome_fantasia,
            'cnpj'             => $e->cnpj,
            'email'            => $e->email,
            'telefone'         => $e->telefone,
            'descricao'        => $e->descricao,
            'logo_url'         => $e->logo_url
                ? (str_starts_with($e->logo_url, 'http')
                    ? $e->logo_url
                    : Storage::disk('public')->url($e->logo_url))
                : null,
            'cep'              => $e->cep,
            'rua'              => $e->rua,
            'logradouro'       => $e->rua, // alias para o frontend
            'numero'           => $e->numero,
            'complemento'      => $e->complemento,
            'bairro'           => $e->bairro,
            'cidade'           => $e->cidade,
            'estado'           => $e->estado,
            'latitude'         => $e->latitude,
            'longitude'        => $e->longitude,
            'plano'            => $e->plano,
            'tipo_empresa'     => $e->tipo_empresa,
            'tipo_acesso'      => $e->tipo_acesso,
            'status'           => $e->status,
            'franquia'         => $e->franquia ? [
                'id'     => $e->franquia->id,
                'nome'   => $e->franquia->nome,
                'codigo' => $e->franquia->codigo,
            ] : null,
            'owner'            => true, // TODO: sub-usuários ainda não implementados
            'menus_permitidos' => ['todos'],
        ];
    }
}
