<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressSkraafoto extends MetisSection
{
    public ?float $lat = null;
    public ?float $lng = null;

    protected function sectionTitle(): string
    {
        return __('Skråfoto');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        $coords = $analysis['property']['coordinates'] ?? null;

        if ($coords) {
            $this->lat = ((float) ($coords['lat'] ?? $coords['latitude'] ?? 0)) ?: null;
            $this->lng = ((float) ($coords['lng'] ?? $coords['longitude'] ?? 0)) ?: null;
        }
    }

    public function getViewerUrlProperty(): ?string
    {
        if (! $this->lat || ! $this->lng) {
            return null;
        }

        // Skråfoto-vieweren forventer center i EPSG:25832 (UTM zone 32N, meter),
        // ikke WGS84-grader — ellers kan den ikke placere punktet og viser
        // "Vælg retning" med et tomt billede.
        [$easting, $northing] = $this->wgs84ToUtm32($this->lat, $this->lng);

        return 'https://skraafoto.dataforsyningen.dk/?'.http_build_query([
            'center' => $easting.','.$northing,
            'orientation' => 'north',
        ]);
    }

    /**
     * WGS84 (lat/lng) → UTM zone 32N (EPSG:25832). Standard Transverse
     * Mercator-projektion (fast geodætisk formel, matcher PostGIS ST_Transform).
     *
     * @return array{0: float, 1: float} [easting, northing]
     */
    protected function wgs84ToUtm32(float $lat, float $lng): array
    {
        $a = 6378137.0;
        $f = 1 / 298.257223563;
        $k0 = 0.9996;
        $e2 = 2 * $f - $f * $f;
        $ep2 = $e2 / (1 - $e2);

        $latRad = deg2rad($lat);
        $lng0Rad = deg2rad(9);

        $n = $a / sqrt(1 - $e2 * sin($latRad) ** 2);
        $t = tan($latRad) ** 2;
        $c = $ep2 * cos($latRad) ** 2;
        $aCoeff = (deg2rad($lng) - $lng0Rad) * cos($latRad);

        $m = $a * (
            (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256) * $latRad
            - (3 * $e2 / 8 + 3 * $e2 ** 2 / 32 + 45 * $e2 ** 3 / 1024) * sin(2 * $latRad)
            + (15 * $e2 ** 2 / 256 + 45 * $e2 ** 3 / 1024) * sin(4 * $latRad)
            - (35 * $e2 ** 3 / 3072) * sin(6 * $latRad)
        );

        $easting = $k0 * $n * (
            $aCoeff
            + (1 - $t + $c) * $aCoeff ** 3 / 6
            + (5 - 18 * $t + $t ** 2 + 72 * $c - 58 * $ep2) * $aCoeff ** 5 / 120
        ) + 500000.0;

        $northing = $k0 * (
            $m + $n * tan($latRad) * (
                $aCoeff ** 2 / 2
                + (5 - $t + 9 * $c + 4 * $c ** 2) * $aCoeff ** 4 / 24
                + (61 - 58 * $t + $t ** 2 + 600 * $c - 330 * $ep2) * $aCoeff ** 6 / 720
            )
        );

        return [round($easting, 2), round($northing, 2)];
    }

    public function render()
    {
        return view('metis::livewire.sections.address-skraafoto');
    }
}
