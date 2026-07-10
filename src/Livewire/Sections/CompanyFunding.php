<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyFunding extends MetisSection
{
    public array $rounds = [];
    public array $summary = [];
    public array $valuationSeries = [];
    public string $currency = 'DKK';

    protected function sectionTitle(): string
    {
        return __('Kapitalhistorik');
    }

    public function mount(string $query): void
    {
        $this->query = $query;

        if (! preg_match('/^\d{8}$/', $query)) {
            return;
        }

        $history = rescue(fn () => app(RegistryApi::class)->fetchFundingHistory($query));

        if (! $history) {
            return;
        }

        $this->rounds = $history['rounds'] ?? [];
        $this->summary = $history['summary'] ?? [];
        $this->valuationSeries = $history['valuation_series'] ?? [];
        $this->currency = $history['currency'] ?? 'DKK';
    }

    public function render()
    {
        return view('metis::livewire.sections.company-funding');
    }
}
