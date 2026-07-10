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

    /**
     * Per-owner expansion state for the inline "Udfold struktur"-toggle.
     * Keyed by ownerKey() (cvr or person_name slug). Value: array of
     * companies the owner is currently active in.
     */
    public array $expandedOwners = [];

    protected function sectionTitle(): string
    {
        return __('Company Structure');
    }

    /**
     * Override the parent placeholder with a structure-shaped skeleton
     * (3 stacked card-rows + connectors) so users can see at a glance
     * that the org-chart is being assembled.
     */
    public function placeholder(): string
    {
        $title = __('Company Structure');
        $loading = __('Loading company structure...');

        return <<<HTML
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <div class="flex items-center justify-between mb-6">
                    <span class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{$title}</span>
                    <div class="flex items-center gap-2 text-blue-600 dark:text-blue-400 text-sm">
                        <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{$loading}</span>
                    </div>
                </div>

                <div class="flex flex-col items-center gap-3 animate-pulse">
                    <div class="flex gap-4">
                        <div class="h-14 w-40 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                        <div class="h-14 w-40 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                    </div>
                    <div class="w-0.5 h-5 bg-zinc-200 dark:bg-zinc-700"></div>
                    <div class="h-16 w-48 bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-200 dark:border-amber-800 rounded-lg"></div>
                    <div class="w-0.5 h-5 bg-zinc-200 dark:bg-zinc-700"></div>
                    <div class="flex gap-4">
                        <div class="h-12 w-36 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                        <div class="h-12 w-36 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                        <div class="h-12 w-36 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                    </div>
                </div>
            </div>
        HTML;
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

        // For each company owner, fetch their owners too (one level deep).
        // NB: tidligere blev EJERENS datterselskaber vist som det søgte selskabs
        // egne når subsidiaries var tomme — faktuelt forkert under et
        // DATTERSELSKABER-label (JEUDAN viste Chr. Augustinus' porteføljeselskaber).
        // Ejerens øvrige selskaber ses korrekt mærket via 'Udfold struktur'.
        foreach ($this->owners as $i => $owner) {
            if (($owner['is_company'] ?? false) && ($owner['cvr'] ?? null)) {
                $parentInfo = rescue(fn () => $api->fetchCompanyInfo($owner['cvr']));
                if ($parentInfo) {
                    $this->owners[$i]['parent_owners'] = $parentInfo['owners'] ?? [];
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
            // Owners don't change during subsidiary-enrichment — preserve the lifted shape
            $this->subsidiaries = $result['subsidiaries'] ?? $this->subsidiaries;
        }
    }

    /**
     * Lazy-load and cache the list of OTHER companies a given owner is active in.
     * Triggered from Alpine.js click on the "Udfold struktur"-button per owner card.
     */
    public function toggleOwnerExpansion(string $ownerKey): void
    {
        if (isset($this->expandedOwners[$ownerKey])) {
            unset($this->expandedOwners[$ownerKey]);

            return;
        }

        $owner = collect($this->owners)->first(fn ($o) => $this->ownerKey($o) === $ownerKey);
        if (! $owner) {
            return;
        }

        $api = app(RegistryApi::class);
        $companies = [];

        if ($owner['is_company'] ?? false) {
            // For company-owners: fetch their structure (subs) + parent_owners (already loaded)
            if ($owner['cvr'] ?? null) {
                $structure = rescue(fn () => $api->fetchCompanyStructure($owner['cvr']), []);
                $companies = collect($structure['subsidiaries'] ?? [])
                    ->map(fn ($s) => [
                        'name' => $s['name'] ?? $s['cvr'],
                        'cvr' => $s['cvr'],
                        'role' => __('Subsidiary'),
                        'share' => $s['ownership_share'] ?? null,
                    ])
                    ->values()
                    ->toArray();
            }
        } else {
            // For person-owners: fetch their roles across all companies
            $rolesData = rescue(fn () => $api->fetchPersonRoles($owner['person_name'] ?? ''));
            $allCompanies = collect($rolesData['data']['companies'] ?? $rolesData['companies'] ?? []);
            $companies = $allCompanies
                ->filter(fn ($c) => collect($c['roles'] ?? [])->contains('is_current', true))
                ->filter(fn ($c) => ($c['cvr'] ?? '') !== $this->query) // exclude searched company
                ->map(function ($c) {
                    $activeRoles = collect($c['roles'] ?? [])->filter(fn ($r) => $r['is_current'] ?? false);

                    return [
                        'name' => $c['name'] ?? '',
                        'cvr' => $c['cvr'] ?? '',
                        'role' => $activeRoles->pluck('role_label')->unique()->implode(', '),
                        'share' => null,
                    ];
                })
                ->values()
                ->toArray();
        }

        $this->expandedOwners[$ownerKey] = $companies;
    }

    public function ownerKey(array $owner): string
    {
        return ($owner['is_company'] ?? false)
            ? 'cvr:'.($owner['cvr'] ?? '')
            : 'person:'.md5($owner['person_name'] ?? '');
    }

    public function render()
    {
        return view('metis::livewire.sections.company-structure');
    }
}
