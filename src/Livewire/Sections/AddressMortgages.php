<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressMortgages extends MetisSection
{
    public array $mortgages = [];
    public int $totalDebt = 0;

    protected function sectionTitle(): string
    {
        return __('Mortgages');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        $this->mortgages = $analysis['property']['mortgages'] ?? [];
        $this->totalDebt = $analysis['property']['total_debt'] ?? 0;
    }

    public function render()
    {
        return view('metis::livewire.sections.address-mortgages');
    }
}
