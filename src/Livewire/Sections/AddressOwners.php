<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressOwners extends MetisSection
{
    public array $owners = [];

    protected function sectionTitle(): string
    {
        return __('Owners');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        $this->owners = $analysis['property']['owners'] ?? [];
    }

    public function render()
    {
        return view('metis::livewire.sections.address-owners');
    }
}
