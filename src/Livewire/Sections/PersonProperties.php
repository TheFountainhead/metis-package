<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class PersonProperties extends MetisSection
{
    public array $personalProperties = [];

    public array $companies = [];

    public array $summary = [];

    protected function sectionTitle(): string
    {
        return __('Owned Properties');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $result = rescue(fn () => app(RegistryApi::class)->fetchPersonPropertyPortfolioByCpr($query));

        $this->personalProperties = $result['personal_properties'] ?? [];
        $this->companies = $result['companies'] ?? [];
        $this->summary = $result['summary'] ?? [];
    }

    public function render()
    {
        return view('metis::livewire.sections.person-properties');
    }
}
