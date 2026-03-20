<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class PersonNetwork extends MetisSection
{
    public array $ownershipTree = [];
    public array $ownershipStandalone = [];
    public array $boardPositions = [];

    protected function sectionTitle(): string
    {
        return 'Selskabsstruktur';
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        $result = rescue(fn () => $api->fetchCompaniesByCpr($query));
        $companies = collect($result['companies'] ?? []);

        // Only companies where the person has CURRENT roles
        $activeCompanies = $companies->filter(fn ($c) => $c['is_active'] ?? false);

        if ($activeCompanies->isEmpty()) {
            return;
        }

        // Classify: direct ownership (legal_owner/shareholder) vs management/board-only
        // beneficial_owner = INDIRECT ownership through company chain, shown as subsidiaries in tree
        $ownershipCompanies = collect();
        $managementOnlyCompanies = collect();

        foreach ($activeCompanies as $company) {
            if ($company['has_direct_ownership'] ?? false) {
                $ownershipCompanies->push($company);
            } else {
                $managementOnlyCompanies->push($company);
            }
        }

        // Build ownership tree
        $this->buildOwnershipTree($api, $ownershipCompanies);

        // Build board/management positions
        $this->boardPositions = $managementOnlyCompanies->map(function ($company) {
            $currentRoles = collect($company['roles'] ?? [])->where('is_current', true);

            return [
                'cvr' => $company['cvr'],
                'name' => $company['name'] ?? $company['cvr'],
                'company_type' => $company['company_type'] ?? null,
                'role' => $currentRoles->pluck('title')->filter()->first()
                    ?? $currentRoles->pluck('role')->filter()->first() ?? '-',
                'start_date' => $currentRoles->pluck('start_date')->filter()->sort()->first(),
            ];
        })->sortBy('name')->values()->toArray();
    }

    protected function buildOwnershipTree(RegistryApi $api, $ownershipCompanies): void
    {
        if ($ownershipCompanies->isEmpty()) {
            return;
        }

        $activeCvrs = $ownershipCompanies->pluck('cvr')->filter()->unique()->values()->toArray();

        // Get cross-ownership relationships
        $crossOwnership = [];
        if (count($activeCvrs) >= 2) {
            $crossOwnership = rescue(fn () => $api->fetchCrossOwnership($activeCvrs), []);
        }

        // Build parent -> children map
        $childCvrs = collect();
        $parentChildMap = [];
        foreach ($crossOwnership['relationships'] ?? [] as $relation) {
            $parentCvr = $relation['parent_cvr'] ?? null;
            $childCvr = $relation['child_cvr'] ?? null;
            if ($parentCvr && $childCvr) {
                $childCvrs->push($childCvr);
                $parentChildMap[$parentCvr][$childCvr] = $relation['ownership_share'] ?? null;
            }
        }

        // Fetch structure for top-level companies
        $companyByCvr = $ownershipCompanies->keyBy('cvr');
        $structureByParent = [];

        foreach ($activeCvrs as $cvr) {
            if ($childCvrs->contains($cvr)) {
                continue;
            }

            $structure = rescue(fn () => $api->fetchCompanyStructure($cvr), []);
            $owners = $structure['owners'] ?? [];
            $subsidiaries = $structure['subsidiaries'] ?? [];

            // Add children from cross-ownership not in subsidiaries
            $subCvrs = collect($subsidiaries)->pluck('cvr')->toArray();
            foreach ($parentChildMap[$cvr] ?? [] as $childCvr => $share) {
                if (! in_array($childCvr, $subCvrs)) {
                    $childCompany = $companyByCvr->get($childCvr);
                    if ($childCompany) {
                        $subsidiaries[] = [
                            'cvr' => $childCvr,
                            'name' => $childCompany['name'] ?? $childCvr,
                            'company_type' => $childCompany['company_type'] ?? null,
                            'ownership_share' => $share,
                        ];
                    }
                }
            }

            $structureByParent[$cvr] = [
                'owners' => $owners,
                'subsidiaries' => $subsidiaries,
            ];
        }

        // Build tree nodes
        $topLevel = $ownershipCompanies->filter(fn ($c) => ! $childCvrs->contains($c['cvr']));

        foreach ($topLevel as $company) {
            $cvr = $company['cvr'];
            $currentRoles = collect($company['roles'] ?? [])->where('is_current', true);
            $ownershipRole = $currentRoles->first(fn ($r) => ! empty($r['ownership_share']));
            $structure = $structureByParent[$cvr] ?? ['owners' => [], 'subsidiaries' => []];

            $node = [
                'cvr' => $cvr,
                'name' => $company['name'] ?? $cvr,
                'company_type' => $company['company_type'] ?? null,
                'ownership_share' => $ownershipRole['ownership_share'] ?? null,
                'owners' => $structure['owners'],
                'subsidiaries' => $structure['subsidiaries'],
            ];

            if (count($structure['subsidiaries']) > 0 || count($structure['owners']) > 0) {
                $this->ownershipTree[] = $node;
            } else {
                $this->ownershipStandalone[] = $node;
            }
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.person-network');
    }
}
