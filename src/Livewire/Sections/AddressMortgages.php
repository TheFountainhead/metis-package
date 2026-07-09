<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressMortgages extends MetisSection
{
    public array $mortgages = [];
    public int $totalDebt = 0;
    public ?float $ltv = null;

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

        $estimatedValue = $analysis['property']['valuation']['estimated_value'] ?? 0;
        if ($estimatedValue > 0 && $this->totalDebt > 0) {
            $this->ltv = round($this->totalDebt / $estimatedValue * 100, 1);
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.address-mortgages');
    }
}
