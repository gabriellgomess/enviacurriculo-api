<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CnpjController extends Controller
{
    /**
     * GET /api/cnpj/{cnpj}
     *
     * Consulta dados de CNPJ via BrasilAPI.
     * Resultados são cacheados por 24 horas para evitar rate limit.
     */
    public function show(string $cnpj)
    {
        $digits = preg_replace('/\D/', '', $cnpj);

        if (strlen($digits) !== 14) {
            return response()->json(['message' => 'CNPJ deve ter 14 dígitos.'], 422);
        }

        $cacheKey = "cnpj:{$digits}";

        $data = Cache::remember($cacheKey, now()->addHours(24), function () use ($digits) {
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->get("https://brasilapi.com.br/api/cnpj/v1/{$digits}");

            if ($response->failed()) {
                return null;
            }

            return $response->json();
        });

        if (is_null($data)) {
            Cache::forget($cacheKey); // não cachear falhas
            return response()->json(['message' => 'CNPJ não encontrado na Receita Federal.'], 404);
        }

        return response()->json([
            'data' => [
                'cnpj'          => $this->formatCnpj($digits),
                'razao_social'  => $data['razao_social'] ?? '',
                'nome_fantasia' => $data['nome_fantasia'] ?? '',
                'email'         => $data['email'] ?? '',
                'telefone'      => $this->formatPhone($data['ddd_telefone_1'] ?? ''),
                'cep'           => $data['cep'] ?? '',
                'rua'           => trim(($data['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($data['logradouro'] ?? '')),
                'numero'        => $data['numero'] ?? '',
                'complemento'   => $data['complemento'] ?? '',
                'bairro'        => $data['bairro'] ?? '',
                'cidade'        => $data['municipio'] ?? '',
                'estado'        => $data['uf'] ?? '',
            ],
        ]);
    }

    private function formatCnpj(string $digits): string
    {
        return preg_replace(
            '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/',
            '$1.$2.$3/$4-$5',
            $digits
        );
    }

    private function formatPhone(string $raw): string
    {
        $d = preg_replace('/\D/', '', $raw);
        if (strlen($d) >= 10) {
            $ddd    = substr($d, 0, 2);
            $numero = substr($d, 2);
            return "({$ddd}) {$numero}";
        }
        return $raw;
    }
}
