<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Franquia;
use App\Services\GeocodeService;

class MapaController extends Controller
{
    public function __construct(private readonly GeocodeService $geocoder) {}

    // GET /admin/mapa
    public function index()
    {
        $franquias  = $this->loadFranquias();
        $empresas   = $this->loadEmpresas();
        $candidatos = $this->loadCandidatos();

        return response()->json([
            'franquias'  => $franquias,
            'empresas'   => $empresas,
            'candidatos' => $candidatos,
            'totals' => [
                'franquias'  => $franquias->count(),
                'empresas'   => $empresas->count(),
                'candidatos' => $candidatos->count(),
            ],
        ]);
    }

    private function loadFranquias()
    {
        return Franquia::whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('active', true)
            ->get(['id', 'codigo', 'nome', 'tipo', 'cidade', 'estado', 'latitude', 'longitude'])
            ->map(fn($f) => [
                'id'        => "f-{$f->id}",
                'tipo'      => 'franquia',
                'nome'      => $f->nome,
                'endereco'  => trim(implode(', ', array_filter([$f->cidade, $f->estado]))),
                'lat'       => (float) $f->latitude,
                'lng'       => (float) $f->longitude,
                'extra'     => $f->tipo,
                'codigo'    => $f->codigo,
            ]);
    }

    private function loadEmpresas()
    {
        $empresas = Empresa::where('status', 'aprovado')
            ->where('active', true)
            ->get(['id', 'razao_social', 'nome_fantasia', 'cidade', 'estado', 'latitude', 'longitude', 'logo_url']);

        return $empresas->map(function ($e) {
            // Geocoding sob demanda (cache via DB)
            if (!$e->latitude || !$e->longitude) {
                $coords = $this->geocoder->geocode(null, null, null, $e->cidade, $e->estado);
                if ($coords) {
                    $e->update(['latitude' => $coords['latitude'], 'longitude' => $coords['longitude']]);
                }
            }

            if (!$e->latitude || !$e->longitude) {
                return null;
            }

            return [
                'id'       => "e-{$e->id}",
                'tipo'     => 'empresa',
                'nome'     => $e->nome_fantasia ?: $e->razao_social,
                'endereco' => trim(implode(', ', array_filter([$e->cidade, $e->estado]))),
                'lat'      => (float) $e->latitude,
                'lng'      => (float) $e->longitude,
                'logo_url' => $e->logo_url,
            ];
        })->filter()->values();
    }

    private function loadCandidatos()
    {
        return Candidato::whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('active', true)
            ->with('user:id,name')
            ->get(['id', 'user_id', 'cidade', 'estado', 'latitude', 'longitude'])
            ->map(fn($c) => [
                'id'       => "c-{$c->id}",
                'tipo'     => 'candidato',
                'nome'     => $c->user?->name ?? 'Candidato',
                'endereco' => trim(implode(', ', array_filter([$c->cidade, $c->estado]))),
                'lat'      => (float) $c->latitude,
                'lng'      => (float) $c->longitude,
            ]);
    }
}
