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
        // 🚨 FOER felterne udtraekkes: et fejlet opslag maa ikke rendere som
        // "ingen data". Se MetisSection::opslagFejlede().
        if ($this->opslagFejlede($analysis)) {
            return;
        }

        $this->valuation = $analysis['property']['valuation'] ?? null;
        $this->history = $analysis['property']['valuation_history'] ?? [];
    }

    public function render()
    {
        return view('metis::livewire.sections.address-valuation');
    }
}
