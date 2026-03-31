<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyProperties extends MetisSection
{
    public ?array $portfolio = null;

    protected function sectionTitle(): string
    {
        return __('Property Portfolio');
    }

    public int $visibleCount = 10;

    public function mount(string $query): void
    {
        $this->query = $query;
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($query));
        $portfolio = $result['portfolio'] ?? null;

        if ($portfolio) {
            // Keep summary but limit properties for Livewire serialization
            $allProperties = $portfolio['properties'] ?? [];
            $portfolio['properties'] = array_slice($allProperties, 0, $this->visibleCount);
            $portfolio['total_count'] = count($allProperties);
        }

        $this->portfolio = $portfolio;
    }

    public function loadMore(): void
    {
        $this->visibleCount += 50;
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($this->query));
        $portfolio = $result['portfolio'] ?? null;

        if ($portfolio) {
            $allProperties = $portfolio['properties'] ?? [];
            $portfolio['properties'] = array_slice($allProperties, 0, $this->visibleCount);
            $portfolio['total_count'] = count($allProperties);
            $this->portfolio = $portfolio;
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-properties');
    }
}
