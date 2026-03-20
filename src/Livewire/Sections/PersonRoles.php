<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class PersonRoles extends MetisSection
{
    public ?string $personName = null;
    public ?array $address = null;
    public array $companies = [];
    public array $properties = [];

    protected function sectionTitle(): string
    {
        return __('Person');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        $result = rescue(fn () => $api->fetchPersonRoles($query));

        if ($result) {
            $this->personName = $result['person_name'] ?? $query;
            $this->address = $result['address'] ?? null;
            $this->companies = $result['companies'] ?? [];
            $this->properties = $result['properties'] ?? [];
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.person-roles');
    }
}
