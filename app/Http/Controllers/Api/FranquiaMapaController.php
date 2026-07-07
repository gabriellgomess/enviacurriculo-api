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
            $data['vagas'] = Vaga::with('empresa:id,razao_social,nome_fantasia,latitude,longitude')
                ->whereIn('empresa_id', $empresaIds)
                ->where('status', 'publicada')
                ->whereHas('empresa', fn($q) => $q->whereNotNull('latitude')->whereNotNull('longitude'))
                ->get(['id', 'empresa_id', 'titulo', 'cidade', 'estado', 'regime_trabalho'])
                ->map(fn($v) => [
                    'id'         => $v->id,
                    'titulo'     => $v->titulo,
                    'empresa'    => $v->empresa?->nome_fantasia ?? $v->empresa?->razao_social,
                    'cidade'     => $v->cidade,
                    'estado'     => $v->estado,
                    'latitude'   => $v->empresa?->latitude,
                    'longitude'  => $v->empresa?->longitude,
                    'modalidade' => $v->regime_trabalho,
                ]);
        }

        if (in_array($tipo, ['candidatos', 'todos'])) {
            $vagaIds = Vaga::whereIn('empresa_id', $empresaIds)->pluck('id');
            $data['candidatos'] = Candidato::with('user:id,name')
                ->whereHas('envios', fn($q) => $q->whereIn('vaga_id', $vagaIds))
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->where('active', true)
                ->get(['id', 'user_id', 'cargo_desejado', 'cidade', 'estado', 'latitude', 'longitude'])
                ->map(fn($c) => [
                    'id'             => $c->id,
                    'nome'           => $c->user?->name,
                    'cargo_desejado' => $c->cargo_desejado,
                    'cidade'         => $c->cidade,
                    'estado'         => $c->estado,
                    'latitude'       => $c->latitude,
                    'longitude'      => $c->longitude,
                ]);
        }

        if (in_array($tipo, ['empresas', 'todos'])) {
            $data['empresas'] = Empresa::where('franquia_id', $franquiaId)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->where('active', true)
                ->get(['id', 'razao_social', 'nome_fantasia', 'cidade', 'estado', 'latitude', 'longitude', 'logo_url'])
                ->map(fn($e) => [
                    'id'           => $e->id,
                    'razao_social' => $e->razao_social,
                    'nome_fantasia'=> $e->nome_fantasia,
                    'cidade'       => $e->cidade,
                    'estado'       => $e->estado,
                    'latitude'     => $e->latitude,
                    'longitude'    => $e->longitude,
                    'logo_url'     => $e->logo_url,
                ]);
        }

        if (in_array($tipo, ['franquias', 'todos'])) {
            $data['franquias'] = Franquia::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->where('active', true)
                ->get(['id', 'nome', 'tipo', 'cidade', 'estado', 'latitude', 'longitude'])
                ->map(fn($f) => [
                    'id'        => $f->id,
                    'nome'      => $f->nome,
                    'tipo'      => $f->tipo,
                    'cidade'    => $f->cidade,
                    'estado'    => $f->estado,
                    'latitude'  => $f->latitude,
                    'longitude' => $f->longitude,
                ]);
        }

        return response()->json(['data' => $data]);
    }
}
