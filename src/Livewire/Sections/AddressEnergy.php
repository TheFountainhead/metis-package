<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressEnergy extends MetisSection
{
    public ?array $energy = null;

    protected function sectionTitle(): string
    {
        return __('Energy Label');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        // 🚨 Et fejlet opslag maa ikke rendere som "ingen data".
        // Se MetisSection::opslagFejlede().
        if ($this->opslagFejlede($analysis)) {
            return;
        }

        $this->energy = $analysis['property']['energy_label'] ?? null;
    }

    public function render()
    {
        return view('metis::livewire.sections.address-energy');
    }
}
