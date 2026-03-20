<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressCompanies extends MetisSection
{
    public array $companies = [];

    protected function sectionTitle(): string
    {
        return __('Companies at Address');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        $this->companies = $analysis['property']['companies_at_address'] ?? [];
    }

    public function render()
    {
        return view('metis::livewire.sections.address-companies');
    }
}
