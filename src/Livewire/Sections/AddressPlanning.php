<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressPlanning extends MetisSection
{
    public ?array $plans = null;

    protected function sectionTitle(): string
    {
        return __('Local Planning');
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


        // local_plans is a wrapper, not the plan list itself; 'local_plans' is
        // the pre-#199 legacy key, kept as fallback since deploy order across
        // repos isn't guaranteed. An error wrapper has neither -> [] -> empty state.
        $this->plans = data_get($analysis, 'property.local_plans.plans')
            ?? data_get($analysis, 'property.local_plans.local_plans', []);
    }

    public function render()
    {
        return view('metis::livewire.sections.address-planning');
    }
}
