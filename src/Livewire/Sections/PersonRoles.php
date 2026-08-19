<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class PersonRoles extends MetisSection
{
    public ?string $personName = null;
    public ?array $address = null;
    public array $companies = [];
    public array $properties = [];

    /** Default: vis kun selskaber hvor personen har en aktuel rolle. */
    public bool $showAllRoles = false;

    protected function sectionTitle(): string
    {
        return __('Person');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        $result = rescue(fn () => $api->fetchPersonRoles($query));

        // 🚨 Et fejlet opslag maa ikke rendere som "0" eller "ingen".
        // rescue() giver null naar kaldet kaster — se MetisSection::opslagFejlede().
        if ($this->opslagFejlede($result)) {
            return;
        }


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
