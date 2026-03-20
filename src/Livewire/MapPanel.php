<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use TheFountainhead\Metis\Services\RegistryApi;
use Illuminate\Contracts\View\View;

class MapPanel extends Component
{
    public ?float $lat = null;
    public ?float $lng = null;
    public ?string $searchType = null;
    public array $markers = [];
    public array $layers = [];
    public bool $layersUnavailable = false;

    public function mount(?float $lat = null, ?float $lng = null, ?string $searchType = null): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->searchType = $searchType;
        $this->loadLayers();
    }

    #[On('search-completed')]
    public function onSearchCompleted($lat, $lng, $type, $markers = []): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->searchType = $type;
        $this->markers = $markers;
        $this->dispatch('map-update');
    }

    protected function loadLayers(): void
    {
        try {
            $result = app(RegistryApi::class)->getMapLayers();

            if (is_array($result) && isset($result['error'])) {
                $this->layersUnavailable = true;
                return;
            }

            $this->layers = is_array($result) ? $result : [];
        } catch (\Throwable) {
            $this->layersUnavailable = true;
        }
    }

    public function render(): View
    {
        return view('metis::livewire.map-panel');
    }
}
