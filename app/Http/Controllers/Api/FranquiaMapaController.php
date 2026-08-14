<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Franquia;
use App\Models\Vaga;
use Illuminate\Http\Request;

class FranquiaMapaController extends Controller
{
    use HasTokenContext;

    public function __construct(private readonly \App\Services\GeocodeService $geocoder) {}

    // GET /franquia/mapa?tipo={vagas|candidatos|empresas|franquias|todos}
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);
        $tipo = $request->query('tipo', 'todos');

        // IDs das empresas da franquia
        $empresaIds = Empresa::where('franquia_id', $franquiaId)->pluck('id');

        $data = [
            'vagas'      => [],
            'candidatos' => [],
            'empresas'   => [],
            'franquias'  => [],
        ];

        if (in_array($tipo, ['vagas', 'todos'])) {
            // A vaga não tem coordenadas próprias; usa a localização da empresa.
            $data['vagas'] = Vaga::with('empresa:id,razao_social,nome_fantasia,latitude,longitude,cidade,estado')
                ->whereIn('empresa_id', $empresaIds)
                ->where('status', 'publicada')
                ->get(['id', 'empresa_id', 'titulo', 'cidade', 'estado', 'regime_trabalho'])
                ->map(function ($v) {
                    $lat = $v->empresa?->latitude;
                    $lng = $v->empresa?->longitude;
                    if ((!$lat || !$lng) && $v->empresa) {
                        $coords = $this->geocoder->geocode(null, null, null, $v->empresa->cidade, $v->empresa->estado);
                        if ($coords) {
                            $v->empresa->update(['latitude' => $coords['latitude'], 'longitude' => $coords['longitude']]);
                            $lat = $coords['latitude'];
                            $lng = $coords['longitude'];
                        }
                    }
                    if (!$lat || !$lng) return null;

                    return [
                        'id'         => $v->id,
                        'titulo'     => $v->titulo,
                        'empresa'    => $v->empresa?->nome_fantasia ?? $v->empresa?->razao_social,
                        'cidade'     => $v->cidade,
                        'estado'     => $v->estado,
                        'latitude'   => (float) $lat,
                        'longitude'  => (float) $lng,
                        'modalidade' => $v->regime_trabalho,
                    ];
                })->filter()->values();
        }

        if (in_array($tipo, ['candidatos', 'todos'])) {
            $vagaIds = Vaga::where('franquia_id', $franquiaId)->pluck('id');
            $data['candidatos'] = Candidato::with('user:id,name')
                ->where(function ($q) use ($franquiaId, $vagaIds) {
                    $q->whereHas('envios', fn($s) => $s->whereIn('vaga_id', $vagaIds))
                      ->orWhere('franquia_id', $franquiaId)
                      ->orWhereNull('franquia_id');
                })
                ->where('active', true)
                ->get(['id', 'user_id', 'cargo_desejado', 'cidade', 'estado', 'bairro', 'latitude', 'longitude'])
                ->map(function ($c) {
                    $lat = $c->latitude;
                    $lng = $c->longitude;
                    if ((!$lat || !$lng) && ($c->cidade || $c->estado)) {
                        $coords = $this->geocoder->geocode(null, null, $c->bairro, $c->cidade, $c->estado);
                        if ($coords) {
                            $c->update(['latitude' => $coords['latitude'], 'longitude' => $coords['longitude']]);
                            $lat = $coords['latitude'];
                            $lng = $coords['longitude'];
                        }
                    }
                    if (!$lat || !$lng) return null;

                    return [
                        'id'             => $c->id,
                        'nome'           => $c->user?->name,
                        'cargo_desejado' => $c->cargo_desejado,
                        'cidade'         => $c->cidade,
                        'estado'         => $c->estado,
                        'latitude'       => (float) $lat,
                        'longitude'      => (float) $lng,
                    ];
                })->filter()->values();
        }

        if (in_array($tipo, ['empresas', 'todos'])) {
            $data['empresas'] = Empresa::where('franquia_id', $franquiaId)
                ->where('active', true)
                ->get(['id', 'razao_social', 'nome_fantasia', 'cidade', 'estado', 'bairro', 'latitude', 'longitude', 'logo_url'])
                ->map(function ($e) {
                    $lat = $e->latitude;
                    $lng = $e->longitude;
                    if ((!$lat || !$lng) && ($e->cidade || $e->estado)) {
                        $coords = $this->geocoder->geocode(null, null, $e->bairro, $e->cidade, $e->estado);
                        if ($coords) {
                            $e->update(['latitude' => $coords['latitude'], 'longitude' => $coords['longitude']]);
                            $lat = $coords['latitude'];
                            $lng = $coords['longitude'];
                        }
                    }
                    if (!$lat || !$lng) return null;

                    return [
                        'id'           => $e->id,
                        'razao_social' => $e->razao_social,
                        'nome_fantasia'=> $e->nome_fantasia,
                        'cidade'       => $e->cidade,
                        'estado'       => $e->estado,
                        'latitude'     => (float) $lat,
                        'longitude'    => (float) $lng,
                        'logo_url'     => $e->logo_url,
                    ];
                })->filter()->values();
        }

        if (in_array($tipo, ['franquias', 'todos'])) {
            $data['franquias'] = Franquia::where('active', true)
                ->get(['id', 'nome', 'tipo', 'cidade', 'estado', 'bairro', 'latitude', 'longitude'])
                ->map(function ($f) {
                    $lat = $f->latitude;
                    $lng = $f->longitude;
                    if ((!$lat || !$lng) && ($f->cidade || $f->estado)) {
                        $coords = $this->geocoder->geocode(null, null, $f->bairro, $f->cidade, $f->estado);
                        if ($coords) {
                            $f->update(['latitude' => $coords['latitude'], 'longitude' => $coords['longitude']]);
                            $lat = $coords['latitude'];
                            $lng = $coords['longitude'];
                        }
                    }
                    if (!$lat || !$lng) return null;

                    return [
                        'id'        => $f->id,
                        'nome'      => $f->nome,
                        'tipo'      => $f->tipo,
                        'cidade'    => $f->cidade,
                        'estado'    => $f->estado,
                        'latitude'  => (float) $lat,
                        'longitude' => (float) $lng,
                    ];
                })->filter()->values();
        }

        return response()->json(['data' => $data]);
    }
}
