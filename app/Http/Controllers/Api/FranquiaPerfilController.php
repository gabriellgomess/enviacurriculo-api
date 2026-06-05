<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Franquia;
use App\Services\GeocodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FranquiaPerfilController extends Controller
{
    use HasTokenContext;

    public function __construct(private readonly GeocodeService $geocoder) {}

    // GET /franquia/perfil
    public function show(Request $request)
    {
        $franquia = Franquia::findOrFail($this->tokenContextId($request));
        return response()->json(['data' => $franquia]);
    }

    // PUT /franquia/perfil
    public function update(Request $request)
    {
        $franquia = Franquia::findOrFail($this->tokenContextId($request));

        $validated = $request->validate([
            'nome'                => 'sometimes|required|string|max:255',
            'responsavel'         => 'nullable|string|max:255',
            'cpf'                 => 'nullable|string|max:14',
            'cnpj'                => 'nullable|string|max:18',
            'email'               => 'nullable|email|max:255',
            'email_franqueado'    => 'nullable|email|max:255',
            'telefone'            => 'nullable|string|max:20',
            'descricao'           => 'nullable|string',
            // Endereço pessoal
            'cep'                 => 'nullable|string|max:9',
            'logradouro'          => 'nullable|string|max:255',
            'numero'              => 'nullable|string|max:20',
            'complemento'         => 'nullable|string|max:100',
            'bairro'              => 'nullable|string|max:100',
            'cidade'              => 'nullable|string|max:100',
            'estado'              => 'nullable|string|size:2',
            // Endereço empresa
            'cep_empresa'         => 'nullable|string|max:9',
            'logradouro_empresa'  => 'nullable|string|max:255',
            'numero_empresa'      => 'nullable|string|max:20',
            'complemento_empresa' => 'nullable|string|max:100',
            'bairro_empresa'      => 'nullable|string|max:100',
            'cidade_empresa'      => 'nullable|string|max:100',
            'estado_empresa'      => 'nullable|string|size:2',
            // Bancário
            'nome_banco'          => 'nullable|string|max:100',
            'codigo_banco'        => 'nullable|string|max:10',
            'agencia'             => 'nullable|string|max:20',
            'numero_conta'        => 'nullable|string|max:30',
            'tipo_conta'          => 'nullable|in:corrente,poupanca',
            'chave_pix'           => 'nullable|string|max:255',
        ]);

        // Detecta mudança nos endereços para re-geocodar
        $enderecoPessoalMudou = collect(['cep','logradouro','numero','bairro','cidade','estado'])
            ->some(fn($f) => array_key_exists($f, $validated) && $validated[$f] !== $franquia->{$f});
        $enderecoEmpresaMudou = collect(['cep_empresa','logradouro_empresa','numero_empresa','bairro_empresa','cidade_empresa','estado_empresa'])
            ->some(fn($f) => array_key_exists($f, $validated) && $validated[$f] !== $franquia->{$f});

        $franquia->update($validated);

        if ($enderecoPessoalMudou) {
            $coords = $this->geocoder->geocode(
                $franquia->logradouro, $franquia->numero, $franquia->bairro, $franquia->cidade, $franquia->estado
            );
            if ($coords) $franquia->update($coords);
        }

        if ($enderecoEmpresaMudou) {
            $coords = $this->geocoder->geocode(
                $franquia->logradouro_empresa, $franquia->numero_empresa,
                $franquia->bairro_empresa, $franquia->cidade_empresa, $franquia->estado_empresa
            );
            if ($coords) {
                $franquia->update([
                    'latitude_empresa'  => $coords['latitude'],
                    'longitude_empresa' => $coords['longitude'],
                ]);
            }
        }

        return response()->json([
            'message' => 'Perfil atualizado.',
            'data'    => $franquia->fresh(),
        ]);
    }

    // POST /franquia/perfil/logo
    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => 'required|image|max:2048']);
        $franquia = Franquia::findOrFail($this->tokenContextId($request));

        if ($franquia->logo_url) {
            $base = Storage::disk('public')->url('');
            $oldPath = str_replace($base, '', $franquia->logo_url);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('logo')->store('franquias/logos', 'public');
        $url  = Storage::disk('public')->url($path);
        $franquia->update(['logo_url' => $url]);

        return response()->json(['logo_url' => $url]);
    }
}
