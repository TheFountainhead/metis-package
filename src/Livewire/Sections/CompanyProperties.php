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

    public function mount(string $query): void
    {
        $this->query = $query;
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($query, limit: 15));
        $this->portfolio = $result['portfolio'] ?? null;
    }

    public function loadMore(): void
    {
        $current = count($this->portfolio['properties'] ?? []);
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($this->query, limit: 50, offset: $current));
        $more = $result['portfolio']['properties'] ?? [];

        if ($more && $this->portfolio) {
            $this->portfolio['properties'] = array_merge($this->portfolio['properties'], $more);
            $this->portfolio['property_count'] = count($this->portfolio['properties']);
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-properties');
    }
}
