<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Models\MetisLookup;
use TheFountainhead\Metis\Services\RegistryApi;

class PersonInfo extends MetisSection
{
    public ?array $properties = null;

    protected function sectionTitle(): string
    {
        return __('Person Information');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $result = rescue(fn () => app(RegistryApi::class)->fetchPropertiesByCpr($query));
        $this->properties = $result['properties'] ?? null;

        // Try to extract name from residence or first property for label
        $residence = $result['residence'] ?? null;
        if ($residence && auth()->check()) {
            $label = trim(($residence['street'] ?? '') . ' ' . ($residence['number'] ?? '') . ', ' . ($residence['zip'] ?? '') . ' ' . ($residence['city'] ?? ''));
            MetisLookup::where('team_id', auth()->user()->current_team_id)
                ->where('type', 'cpr')
                ->where('query', $query)
                ->whereNull('label')
                ->latest()
                ->first()
                ?->update(['label' => $label ?: null]);
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.person-info');
    }
}
