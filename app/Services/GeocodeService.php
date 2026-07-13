<?php

namespace App\Services;

class GeocodeService
{
    /**
     * Geocodifica um endereço usando Nominatim (OpenStreetMap) via cURL.
     * Retorna ['latitude' => float, 'longitude' => float] ou null se não encontrar.
     */
    public function geocode(
        ?string $logradouro,
        ?string $numero,
        ?string $bairro,
        ?string $cidade,
        ?string $estado,
    ): ?array {
        // Tenta endereço completo primeiro
        $coords = $this->queryNominatim($logradouro, $numero, $bairro, $cidade, $estado);
        if ($coords) {
            return $coords;
        }

        // Fallback 1: sem número
        if ($numero) {
            $coords = $this->queryNominatim($logradouro, null, $bairro, $cidade, $estado);
            if ($coords) {
                return $coords;
            }
        }

        // Fallback 2: apenas rua + cidade + estado
        if ($logradouro) {
            $coords = $this->queryNominatim($logradouro, null, null, $cidade, $estado);
            if ($coords) {
                return $coords;
            }
        }

        // Fallback 3: apenas bairro + cidade + estado
        if ($bairro) {
            $coords = $this->queryNominatim(null, null, $bairro, $cidade, $estado);
            if ($coords) {
                return $coords;
            }
        }

        // Fallback 4: apenas cidade + estado
        if ($cidade) {
            $coords = $this->queryNominatim(null, null, null, $cidade, $estado);
            if ($coords) {
                return $coords;
            }
        }

        return null;
    }

    private function queryNominatim(
        ?string $logradouro,
        ?string $numero,
        ?string $bairro,
        ?string $cidade,
        ?string $estado,
    ): ?array {
        $parts = array_filter([
            $logradouro && $numero ? "{$logradouro}, {$numero}" : $logradouro,
            $bairro,
            $cidade,
            $estado,
            'Brasil',
        ]);

        if (count($parts) < 2) {
            return null;
        }

        $query = urlencode(implode(', ', $parts));
        $url   = "https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q={$query}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'EnviaCurriculo/1.0 (api.nexustech.net.br)',
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            return null;
        }

        $data = json_decode($response, true);

        if (empty($data[0]['lat'])) {
            return null;
        }

        return [
            'latitude'  => (float) $data[0]['lat'],
            'longitude' => (float) $data[0]['lon'],
        ];
    }

    /**
     * Consulta o ViaCEP e retorna os dados do endereço ou null.
     */
    public function lookupCep(string $cep): ?array
    {
        $clean = preg_replace('/\D/', '', $cep);

        if (strlen($clean) !== 8) {
            return null;
        }

        $url = "https://viacep.com.br/ws/{$clean}/json/";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'EnviaCurriculo/1.0',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);

        if (!empty($data['erro'])) {
            return null;
        }

        return [
            'cep'        => $data['cep']        ?? null,
            'logradouro' => $data['logradouro']  ?? null,
            'bairro'     => $data['bairro']      ?? null,
            'cidade'     => $data['localidade']  ?? null,
            'estado'     => $data['uf']          ?? null,
        ];
    }
}
