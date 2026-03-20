<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class PersonCompanies extends MetisSection
{
    public array $companies = [];

    protected function sectionTitle(): string
    {
        return __('Companies & Ownership');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompaniesByCpr($query));
        $this->companies = collect($result['companies'] ?? [])
            ->sortBy([
                // Active first
                fn ($a, $b) => ($b['is_active'] ?? false) <=> ($a['is_active'] ?? false),
                // Then by equity descending (largest first)
                function ($a, $b) {
                    $aFin = $a['financials'] ?? null;
                    $bFin = $b['financials'] ?? null;
                    $aEq = is_array($aFin) && array_is_list($aFin) ? ($aFin[0]['equity'] ?? 0) : ($aFin['equity'] ?? 0);
                    $bEq = is_array($bFin) && array_is_list($bFin) ? ($bFin[0]['equity'] ?? 0) : ($bFin['equity'] ?? 0);
                    return abs($bEq) <=> abs($aEq);
                },
            ])
            ->values()
            ->toArray();
    }

    public function render()
    {
        return view('metis::livewire.sections.person-companies');
    }
}
