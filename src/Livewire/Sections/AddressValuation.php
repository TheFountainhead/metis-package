<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressValuation extends MetisSection
{
    public ?array $valuation = null;
    public array $history = [];

    protected function sectionTitle(): string
    {
        return __('Property Valuation');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        $this->valuation = $analysis['property']['valuation'] ?? null;
        $this->history = $analysis['property']['valuation_history'] ?? [];
    }

    public function render()
    {
        return view('metis::livewire.sections.address-valuation');
    }
}
