<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeocodeService;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    public function __construct(private GeocodeService $geocode) {}

    /**
     * GET /api/geocode/cep?cep=01310100
     * Público — qualquer painel pode chamar sem token.
     */
    public function cep(Request $request)
    {
        $request->validate(['cep' => 'required|string']);

        $data = $this->geocode->lookupCep($request->cep);

        if (!$data) {
            return response()->json(['message' => 'CEP não encontrado.'], 404);
        }

        return response()->json($data);
    }

    /**
     * GET /api/geocode/address?logradouro=...&numero=...&bairro=...&cidade=...&estado=...
     * Público — qualquer painel pode chamar sem token.
     */
    public function address(Request $request)
    {
        $request->validate([
            'cidade' => 'required|string',
            'estado' => 'required|string|size:2',
        ]);

        $coords = $this->geocode->geocode(
            logradouro: $request->logradouro,
            numero:     $request->numero,
            bairro:     $request->bairro,
            cidade:     $request->cidade,
            estado:     $request->estado,
        );

        if (!$coords) {
            return response()->json(['message' => 'Endereço não encontrado.'], 404);
        }

        return response()->json($coords);
    }
}
