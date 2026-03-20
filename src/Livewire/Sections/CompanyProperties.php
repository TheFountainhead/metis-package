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
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($query));
        $this->portfolio = $result['portfolio'] ?? null;
    }

    public function render()
    {
        return view('metis::livewire.sections.company-properties');
    }
}
