<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyRoles extends MetisSection
{
    public array $roles = [];

    protected function sectionTitle(): string
    {
        return __('Management & Roles');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        // Try local DB first
        $result = rescue(fn () => $api->fetchRolesByCvr([$query]));
        $this->roles = $result['companies'][0]['roles'] ?? [];

        // Fallback: fetch directly from CVR Elasticsearch
        if (empty($this->roles)) {
            $company = rescue(fn () => $api->fetchCompanyInfo($query));
            $this->roles = $company['roles'] ?? [];
        }

        // Resolve CVR numbers for company-type roles that only have a name
        $companyPattern = '/\b(A\/S|ApS|K\/S|I\/S|P\/S|AmbA|AMBA|IVS|FONDE[NT]|HOLDING|PARTNERSELSKAB|KOMMANDITSELSKAB)\b/i';

        foreach ($this->roles as $i => $role) {
            $name = $role['person_name'] ?? '';
            $hasCvr = ! empty($role['participant_cvr']) || ! empty($role['parent_company_cvr']);

            if (! $hasCvr && preg_match($companyPattern, $name)) {
                // Search for this company by name to get its CVR
                $matches = rescue(fn () => $api->searchByName($name), []);
                $exact = collect($matches)->first(fn ($c) => mb_strtolower($c['name'] ?? '') === mb_strtolower($name));
                if ($exact) {
                    $this->roles[$i]['participant_cvr'] = $exact['cvr'];
                    $this->roles[$i]['is_company'] = true;
                }
            }
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-roles');
    }
}
