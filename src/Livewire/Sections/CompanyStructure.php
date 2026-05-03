<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyStructure extends MetisSection
{
    public array $owners = [];
    public array $ultimateOwners = [];
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

        $this->liftUltimateBeneficialOwners();

        $status = rescue(fn () => app(RegistryApi::class)->getEnrichmentStatus($query));
        $this->enriching = in_array($status['status'] ?? '', ['pending', 'running']);
        $this->companiesFound = $status['companies_found'] ?? 0;
    }

    /**
     * If a person-owner of the searched company is also listed as a parent-owner
     * (grand-parent) of a company-owner on the same level, lift the person up to
     * a separate "ultimate beneficial owners" row above the legal owners.
     *
     * Example: Jeannine 100% direct + Tonsbakken Holding 100% direct, where Jeannine
     * is also listed as parent-owner of Tonsbakken Holding → Jeannine is the
     * ultimate beneficial owner; Tonsbakken Holding is the legal owner.
     */
    protected function liftUltimateBeneficialOwners(): void
    {
        $companyOwners = collect($this->owners)->filter(fn ($o) => $o['is_company'] ?? false);

        $lifted = [];
        $remaining = [];

        foreach ($this->owners as $owner) {
            if ($owner['is_company'] ?? false) {
                $remaining[] = $owner;
                continue;
            }

            $isUltimate = false;
            foreach ($companyOwners as $company) {
                foreach ($company['parent_owners'] ?? [] as $parentOwner) {
                    if ($this->ownersMatch($owner, $parentOwner)) {
                        $isUltimate = true;
                        break 2;
                    }
                }
            }

            if ($isUltimate) {
                $lifted[] = $owner;
            } else {
                $remaining[] = $owner;
            }
        }

        $this->ultimateOwners = $lifted;
        $this->owners = $remaining;
    }

    protected function ownersMatch(array $a, array $b): bool
    {
        $nameA = trim(strtolower($a['person_name'] ?? ''));
        $nameB = trim(strtolower($b['person_name'] ?? ''));

        if ($nameA !== '' && $nameA === $nameB) {
            return true;
        }

        $cprA = $a['cpr'] ?? null;
        $cprB = $b['cpr'] ?? null;
        if ($cprA && $cprB && $cprA === $cprB) {
            return true;
        }

        return false;
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
            // Owners don't change during subsidiary-enrichment — preserve the lifted shape
            $this->subsidiaries = $result['subsidiaries'] ?? $this->subsidiaries;
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-structure');
    }
}
