<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Models\MetisLookup;
use TheFountainhead\Metis\Services\RegistryApi;

class CompanyInfo extends MetisSection
{
    public ?array $company = null;

    protected function sectionTitle(): string
    {
        return __('Company Information');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        // Always fetch from CVR API for complete data (type, financials, contact, etc.)
        $info = rescue(fn () => $api->fetchCompanyInfo($query));
        if ($info && isset($info['name'])) {
            $this->company = $info;
        }

        if ($this->company && auth()->check()) {
            MetisLookup::where('team_id', auth()->user()->current_team_id)
                ->where('type', 'cvr')
                ->where('query', $query)
                ->whereNull('label')
                ->latest()
                ->first()
                ?->update(['label' => $this->company['name'] ?? null]);
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-info');
    }
}
