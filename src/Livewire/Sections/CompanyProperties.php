<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyProperties extends MetisSection
{
    public ?array $summary = null;

    public ?array $properties = null;

    public int $offset = 0;

    public int $totalCount = 0;

    protected int $pageSize = 25;

    protected function sectionTitle(): string
    {
        return __('Property Portfolio');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($query, limit: $this->pageSize));
        $portfolio = $result['portfolio'] ?? null;

        if ($portfolio) {
            $this->summary = [
                'total_count' => $portfolio['total_count'] ?? 0,
                'total_valuation' => $portfolio['total_valuation'] ?? 0,
                'total_area' => $portfolio['total_area'] ?? 0,
            ];
            $this->properties = $portfolio['properties'] ?? [];
            $this->totalCount = $portfolio['total_count'] ?? 0;
            $this->offset = count($this->properties);
        }
    }

    public function loadMore(): void
    {
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($this->query, limit: $this->pageSize, offset: $this->offset));
        $more = $result['portfolio']['properties'] ?? [];

        if ($more) {
            $this->properties = array_merge($this->properties, $more);
            $this->offset = count($this->properties);
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-properties');
    }
}
