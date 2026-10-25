<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\ForeignOwner;
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
        if ($this->opslagAfvist($query)) {
            return;
        }

        $this->query = $query;

        if (! preg_match('/^\d{8}$/', $query)) {
            return;
        }

        $history = rescue(fn () => app(RegistryApi::class)->fetchFundingHistory($query));

        if (! $history) {
            return;
        }

        $this->rounds = array_map(fn (array $round) => [
            ...$round,
            'owner_changes' => array_map(fn (array $change) => [
                ...$change,
                // ERST krav 1.5: en udenlandsk ejer markeres med land og
                // enhedstype. registry-api saetter country kun for udenlandske.
                'foreign_label' => ($change['country'] ?? null)
                    ? ForeignOwner::label($change['country'], $change['unit_type'] ?? null)
                    : null,
            ], $round['owner_changes'] ?? []),
        ], $history['rounds'] ?? []);
        $this->summary = $history['summary'] ?? [];
        $this->valuationSeries = $history['valuation_series'] ?? [];
        $this->currency = $history['currency'] ?? 'DKK';
    }

    public function render()
    {
        return view('metis::livewire.sections.company-funding');
    }
}
