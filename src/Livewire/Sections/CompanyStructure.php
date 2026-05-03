<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyStructure extends MetisSection
{
    public array $owners = [];
    public array $subsidiaries = [];
    public bool $enriching = false;
    public int $companiesFound = 0;
    public ?string $companyName = null;

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
        $this->companyName = $result['name'] ?? null;

        // Fallback: fetch owners + name from CVR Elasticsearch
        if (empty($this->owners) || ! $this->companyName) {
            $company = rescue(fn () => $api->fetchCompanyInfo($query));
            if (empty($this->owners)) {
                $this->owners = $company['owners'] ?? [];
            }
            $this->companyName = $this->companyName ?? ($company['name'] ?? null);
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

        $status = rescue(fn () => app(RegistryApi::class)->getEnrichmentStatus($query));
        $this->enriching = in_array($status['status'] ?? '', ['pending', 'running']);
        $this->companiesFound = $status['companies_found'] ?? 0;
    }

    public function pollForUpdates(): void
    {
        if (! $this->enriching) {
            return;
        }

        $status = rescue(fn () => app(RegistryApi::class)->getEnrichmentStatus($this->query));
        $newStatus = $status['status'] ?? 'completed';
        $this->companiesFound = $status['companies_found'] ?? 0;

        if (in_array($newStatus, ['completed', 'failed'])) {
            $this->enriching = false;
            $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyStructure($this->query), []);
            $this->owners = $result['owners'] ?? $this->owners;
            $this->subsidiaries = $result['subsidiaries'] ?? $this->subsidiaries;
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-structure');
    }
}
