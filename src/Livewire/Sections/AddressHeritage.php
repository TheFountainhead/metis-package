<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressHeritage extends MetisSection
{
    public ?array $heritage = null;

    protected function sectionTitle(): string
    {
        return __('Heritage & Protection');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        $this->heritage = $analysis['property']['heritage'] ?? null;
    }

    public function render()
    {
        return view('metis::livewire.sections.address-heritage');
    }
}
