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

        // Vieweren tolker center-koordinater med x < 20 som WGS84 lon,lat.
        return 'https://skraafoto.dataforsyningen.dk/?'.http_build_query([
            'center' => $this->lng.','.$this->lat,
            'orientation' => 'north',
        ]);
    }

    public function render()
    {
        return view('metis::livewire.sections.address-skraafoto');
    }
}
