<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressOwners extends MetisSection
{
    public array $owners = [];

    protected function sectionTitle(): string
    {
        return __('Owners');
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

        $this->owners = $analysis['property']['owners'] ?? [];
    }

    public function render()
    {
        return view('metis::livewire.sections.address-owners');
    }
}
