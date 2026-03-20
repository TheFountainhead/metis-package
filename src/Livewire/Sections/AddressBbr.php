<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Models\MetisLookup;
use TheFountainhead\Metis\Services\RegistryApi;

class AddressBbr extends MetisSection
{
    public ?array $bbr = null;
    public ?string $address = null;

    protected function sectionTitle(): string
    {
        return __('Building Data (BBR)');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        $this->bbr = $analysis['property']['bbr'] ?? null;
        $this->address = $analysis['property']['address'] ?? $query;

        // Update lookup label
        if ($this->address) {
            MetisLookup::where('team_id', auth()->user()->current_team_id)
                ->where('type', 'address')
                ->where('query', $query)
                ->whereNull('label')
                ->latest()
                ->first()
                ?->update(['label' => $this->address]);
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.address-bbr');
    }
}
