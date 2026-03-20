<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyTax extends MetisSection
{
    public array $records = [];

    protected function sectionTitle(): string
    {
        return __('Tax Records');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyTaxRecords($query));
        $this->records = $result['records'] ?? [];
    }

    public function render()
    {
        return view('metis::livewire.sections.company-tax');
    }
}
