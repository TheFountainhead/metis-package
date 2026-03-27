<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressComparison extends MetisSection
{
    public ?array $comparison = null;
    public ?array $rentalEstimate = null;
    public ?array $profitability = null;
    public bool $showDetail = false;

    protected function sectionTitle(): string
    {
        return __('Markedsanalyse');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        $analysis = $api->resolveAddressAnalysis($query);
        $this->rentalEstimate = data_get($analysis, 'property.rental_estimate');
        $this->profitability = data_get($analysis, 'property.profitability');

        $this->comparison = $api->resolvePropertyComparison($query);
    }

    public function render()
    {
        return view('metis::livewire.sections.address-comparison');
    }
}
