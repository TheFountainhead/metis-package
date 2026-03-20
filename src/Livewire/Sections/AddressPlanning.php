<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressPlanning extends MetisSection
{
    public ?array $plans = null;

    protected function sectionTitle(): string
    {
        return __('Local Planning');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        $this->plans = $analysis['property']['local_plans'] ?? null;
    }

    public function render()
    {
        return view('metis::livewire.sections.address-planning');
    }
}
