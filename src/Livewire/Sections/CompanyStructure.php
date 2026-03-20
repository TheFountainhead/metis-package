<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyStructure extends MetisSection
{
    public array $owners = [];
    public array $subsidiaries = [];

    protected function sectionTitle(): string
    {
        return __('Company Structure');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        // Try local DB first (has full hierarchy)
        $result = rescue(fn () => $api->fetchCompanyStructure($query), []);
        $this->owners = $result['owners'] ?? [];
        $this->subsidiaries = $result['subsidiaries'] ?? [];

        // Fallback: fetch owners from CVR Elasticsearch
        if (empty($this->owners)) {
            $company = rescue(fn () => $api->fetchCompanyInfo($query));
            $this->owners = $company['owners'] ?? [];
        }

        // For each company owner, fetch their owners too (one level deep)
        foreach ($this->owners as $i => $owner) {
            if (($owner['is_company'] ?? false) && ($owner['cvr'] ?? null)) {
                $parentInfo = rescue(fn () => $api->fetchCompanyInfo($owner['cvr']));
                if ($parentInfo) {
                    $this->owners[$i]['parent_owners'] = $parentInfo['owners'] ?? [];
                }

                // Also fetch subsidiaries of parent if we don't have them yet
                if (empty($this->subsidiaries)) {
                    $parentStructure = rescue(fn () => $api->fetchCompanyStructure($owner['cvr']), []);
                    $parentSubs = $parentStructure['subsidiaries'] ?? [];
                    // Filter: only show sibling companies (not the current company itself)
                    $this->subsidiaries = collect($parentSubs)
                        ->filter(fn ($sub) => ($sub['cvr'] ?? '') !== $query)
                        ->values()
                        ->toArray();
                }
            }
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-structure');
    }
}
