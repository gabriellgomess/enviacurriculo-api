<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Candidato;
use App\Services\GeocodeService;

class GeocodeCandidates extends Command
{
    protected $signature = 'ec:geocode-candidates';
    protected $description = 'Geocodifica candidatos sem latitude ou longitude usando Nominatim';

    public function handle(GeocodeService $geocoder)
    {
        $candidates = Candidato::whereNull('latitude')
            ->orWhereNull('longitude')
            ->get();

        $this->info("Encontrados " . $candidates->count() . " candidatos sem latitude ou longitude.");

        if ($candidates->count() === 0) {
            $this->info("Todos os candidatos já possuem coordenadas.");
            return 0;
        }

        $bar = $this->output->createProgressBar($candidates->count());
        $bar->start();

        foreach ($candidates as $c) {
            // Monta dados do endereço para geocodificação
            $coords = $geocoder->geocode(
                $c->rua,
                $c->numero,
                $c->bairro,
                $c->cidade,
                $c->estado
            );

            if ($coords) {
                $c->update([
                    'latitude'  => $coords['latitude'],
                    'longitude' => $coords['longitude']
                ]);
            }

            $bar->advance();
            // Respeita limite de 1 requisição por segundo do Nominatim (OpenStreetMap)
            sleep(1);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Geocodificação concluída com sucesso!");
        return 0;
    }
}
