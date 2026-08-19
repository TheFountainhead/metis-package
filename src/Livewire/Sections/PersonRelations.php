<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class PersonRelations extends MetisSection
{
    public array $relations = [];

    protected function sectionTitle(): string
    {
        return __('Relations');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        // Get person's companies
        $result = rescue(fn () => $api->fetchCompaniesByCpr($query));

        // 🚨 Et fejlet opslag maa ikke rendere som "0" eller "ingen".
        // rescue() giver null naar kaldet kaster — se MetisSection::opslagFejlede().
        if ($this->opslagFejlede($result)) {
            return;
        }

        $companies = collect($result['companies'] ?? []);

        // CVRs where the queried person currently has an active role
        $currentCvrs = $companies
            ->filter(fn ($c) => ($c['is_active'] ?? false) || ($c['status'] ?? '') === 'NORMAL')
            ->pluck('cvr')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // All CVRs (including historical) for fetching relations
        $allCvrs = $companies
            ->pluck('cvr')
            ->filter()
            ->unique()
            ->values();

        // Also include subsidiary CVRs from company structures
        $subsidiaryCvrs = collect();
        foreach ($currentCvrs as $cvr) {
            $structure = rescue(fn () => $api->fetchCompanyStructure($cvr), []);
            $this->collectSubsidiaryCvrs($structure['subsidiaries'] ?? [], $subsidiaryCvrs);
        }

        $allCvrs = $allCvrs->merge($subsidiaryCvrs)->unique()->values()->toArray();

        if (empty($allCvrs)) {
            return;
        }

        // Fetch all roles for these companies, excluding the CPR person
        $excludeCpr = preg_replace('/\D/', '', $query);
        $data = rescue(fn () => $api->fetchRolesByCvr($allCvrs, $excludeCpr), []);

        // Group roles by person name across all companies
        $personMap = [];
        foreach ($data['companies'] ?? [] as $company) {
            $cvr = $company['cvr'] ?? '-';
            $personCurrentInCompany = in_array($cvr, $currentCvrs);

            foreach ($company['roles'] ?? [] as $role) {
                $name = $role['person_name'] ?? null;
                if (! $name) {
                    continue;
                }

                if (! isset($personMap[$name])) {
                    $personMap[$name] = [
                        'name' => $name,
                        'is_current' => false,
                        'companies' => [],
                    ];
                }

                // Only mark as current if BOTH persons are active in this company
                $roleIsCurrent = ($role['is_current'] ?? false) && $personCurrentInCompany;
                if ($roleIsCurrent) {
                    $personMap[$name]['is_current'] = true;
                }

                $personMap[$name]['companies'][] = [
                    'company_name' => $company['name'] ?? '-',
                    'cvr' => $cvr,
                    'role_label' => $role['role_label'] ?? $role['title'] ?? $role['role'] ?? '-',
                    'ownership_share' => $role['ownership_share'] ?? null,
                    'is_current' => $roleIsCurrent,
                    'start_date' => $role['start_date'] ?? null,
                    'end_date' => $role['end_date'] ?? null,
                ];
            }
        }

        // Sort: active first, then alphabetically
        $this->relations = collect($personMap)
            ->values()
            ->sort(function ($a, $b) {
                if ($a['is_current'] !== $b['is_current']) {
                    return $b['is_current'] <=> $a['is_current'];
                }

                return strcasecmp($a['name'], $b['name']);
            })
            ->values()
            ->toArray();
    }

    protected function collectSubsidiaryCvrs(array $subsidiaries, \Illuminate\Support\Collection $cvrs): void
    {
        foreach ($subsidiaries as $sub) {
            if ($sub['cvr'] ?? null) {
                $cvrs->push($sub['cvr']);
            }
            $this->collectSubsidiaryCvrs($sub['children'] ?? [], $cvrs);
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.person-relations');
    }
}
