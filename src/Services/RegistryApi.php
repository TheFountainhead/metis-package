<?php

namespace TheFountainhead\Metis\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RegistryApi
{
    protected function client()
    {
        return Http::withToken(config('metis.registry_api.key'))
            ->acceptJson()
            ->timeout(30)
            ->baseUrl(config('metis.registry_api.url'));
    }

    public function fetchCompany(string $cvr): array
    {
        $result = $this->get("/v1/cvr/company/{$cvr}");

        if (isset($result['error'])) {
            return $result;
        }

        $company = $result['company'] ?? null;

        if (! $company) {
            return [];
        }

        $persons = collect($company['roles'] ?? [])
            ->filter(fn ($r) => $r['is_current'] ?? true)
            ->map(fn ($role) => [
                'name' => $role['person_name'] ?? $role['parent_company_name'] ?? 'Ukendt',
                'role' => $role['role_label'] ?? $role['role'] ?? '',
            ])
            ->values()
            ->all();

        return [
            'company' => [
                'name' => $company['name'] ?? 'Ukendt',
                'cvr' => $company['cvr'] ?? $cvr,
                'status' => $company['status'] ?? '',
                'type' => $company['long_company_type'] ?? $company['company_type'] ?? '',
                'address' => trim(($company['address'] ?? '') . ', ' . ($company['postal_code'] ?? '') . ' ' . ($company['city'] ?? ''), ', '),
                'industry' => $company['industry'] ?? null,
                'founded' => $company['founded_date'] ?? null,
            ],
            'persons' => $persons,
        ];
    }

    public function searchByName(string $name): array
    {
        $result = $this->post('/v1/cvr/search-by-name', ['name' => $name]);

        return $result['companies'] ?? [];
    }

    public function searchPersonByName(string $name): array
    {
        $result = $this->fetchPersonRoles($name);

        if (! $result || ! isset($result['person_name'])) {
            return [];
        }

        // Transform to array of persons with roles for blade template
        $roles = collect($result['companies'] ?? [])
            ->filter(fn ($c) => ($c['status'] ?? '') === 'NORMAL' || ($c['status'] ?? '') === '')
            ->flatMap(fn ($company) => collect($company['roles'] ?? [])
                ->map(fn ($role) => [
                    'company' => $company['name'] ?? '',
                    'cvr' => $company['cvr'] ?? '',
                    'role' => $role['role_label'] ?? '',
                    'is_current' => $role['is_current'] ?? false,
                ]))
            ->unique(fn ($r) => $r['cvr'].$r['role'])
            ->values()
            ->all();

        // Find companies where person is owner (Reelle ejere / EJERREGISTER)
        $ownedCompanies = collect($result['companies'] ?? [])
            ->filter(fn ($c) => ($c['status'] ?? '') === 'NORMAL')
            ->filter(fn ($c) => collect($c['roles'] ?? [])
                ->contains(fn ($r) => in_array($r['role_label'] ?? '', ['Reelle ejere', 'EJERREGISTER']) && ($r['is_current'] ?? false)))
            ->take(10)
            ->map(function ($c) {
                $cvr = $c['cvr'] ?? '';
                $ownership = collect($c['roles'] ?? [])
                    ->where('is_current', true)
                    ->whereIn('role_label', ['Reelle ejere', 'EJERREGISTER'])
                    ->max('ownership_share');

                // Count properties via cached portfolio or quick API check
                $propertyCount = rescue(function () use ($cvr) {
                    $result = $this->client()->timeout(5)
                        ->get("/v1/company/{$cvr}/property-portfolio", ['limit' => 0])
                        ->json('data.portfolio.total_count');

                    return $result ?? 0;
                }, 0);

                return [
                    'name' => $c['name'] ?? '',
                    'cvr' => $cvr,
                    'ownership' => $ownership,
                    'property_count' => $propertyCount,
                ];
            })
            ->values()
            ->all();

        $totalProperties = collect($ownedCompanies)->sum('property_count');

        return [[
            'name' => $result['person_name'],
            'roles' => $roles,
            'owned_companies' => $ownedCompanies,
            'total_properties' => $totalProperties,
        ]];
    }

    public function fetchPropertyByAddress(string $address): array
    {
        $parsed = $this->parseAddress($address);

        return $this->fetchProperty($parsed);
    }

    public function fetchProperty(array $searchData): array
    {
        $result = $this->post('/v1/property/analysis', $searchData);

        if (isset($result['error'])) {
            return $result;
        }

        $prop = $result['property'] ?? [];

        if (empty($prop)) {
            return [];
        }

        $bbr = $prop['bbr'] ?? [];
        $val = $prop['valuation'] ?? null;
        $owners = $prop['owners'] ?? [];

        $transformed = [
            'property' => [
                'address' => trim(($prop['address'] ?? '').', '.($prop['postal_code'] ?? '').' '.($prop['city'] ?? ''), ', '),
                'matrikel' => $prop['matrikel_id'] ?? null,
                'bfe' => $prop['matrikel']['bfe'] ?? $prop['matrikel_id'] ?? null,
                'type' => $bbr['usage_code'] ?? null,
                'area' => $bbr['total_area'] ?? null,
                'built' => $bbr['year_built'] ?? null,
            ],
        ];

        if ($val) {
            $transformed['valuation'] = [
                'property_value' => $val['estimated_value'] ?? 0,
                'land_value' => $val['land_value'] ?? 0,
                'year' => substr($val['date'] ?? $val['valuation_date'] ?? '', 0, 4),
            ];
        }

        if (! empty($owners)) {
            $owner = $owners[0];
            $transformed['owner'] = [
                'name' => $owner['name'] ?? 'Ukendt',
                'cvr' => $owner['identifier'] ?? $owner['cvr_nr'] ?? null,
            ];
        }

        $companies = $prop['companies_at_address'] ?? [];
        if (! empty($companies)) {
            $transformed['companies_at_address'] = collect($companies)->map(fn ($c) => [
                'name' => $c['name'] ?? '',
                'cvr' => $c['cvr'] ?? '',
            ])->all();
        }

        return $transformed;
    }

    public function fetchValuation(string $matrikelId): ?array
    {
        return $this->get("/v1/valuations/{$matrikelId}");
    }

    public function addressAutocomplete(string $query, int $limit = 10): array
    {
        return $this->get('/v1/map/autocomplete', ['q' => $query, 'limit' => $limit]) ?? [];
    }

    public function getMapLayers(): array
    {
        return $this->get('/v1/map/layers') ?? [];
    }

    public function fetchCrossOwnership(array $cvrs): array
    {
        return $this->post('/v1/cvr/cross-ownership', ['cvr_numbers' => $cvrs]);
    }

    public function fetchRolesByCvr(array $cvrs, ?string $excludeCpr = null): array
    {
        $payload = ['cvr_numbers' => $cvrs];
        if ($excludeCpr) {
            $payload['exclude_cpr'] = $excludeCpr;
        }

        return $this->post('/v1/cvr/roles-by-cvr', $payload);
    }

    public function fetchCompanyInfo(string $cvr): ?array
    {
        try {
            $response = $this->client()
                ->get("/v1/cvr/company/{$cvr}");

            return $response->json('data.company');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function fetchCompanyStructure(string $cvr): array
    {
        return $this->post('/v1/cvr/company-structure', ['cvr_numbers' => [$cvr]]) ?? [];
    }

    public function fetchCompanyPropertyPortfolio(string $cvr, int $limit = 25, int $offset = 0): ?array
    {
        $cacheKey = "metis:company_property_portfolio:{$cvr}:{$limit}:{$offset}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($cvr, $limit, $offset) {
            try {
                return $this->client()
                    ->timeout(30)
                    ->get("/v1/company/{$cvr}/property-portfolio", [
                        'limit' => $limit,
                        'offset' => $offset,
                    ])
                    ->throw()
                    ->json('data');
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    public function getEnrichmentStatus(string $cvr): ?array
    {
        try {
            $response = $this->client()
                ->timeout(5)
                ->get("/v1/enrichment/{$cvr}/status");

            if (! $response->successful()) {
                return null;
            }

            return $response->json('data');
        } catch (\Throwable) {
            return null;
        }
    }

    public function fetchCompanyTaxRecords(string $cvr): ?array
    {
        try {
            return $this->client()
                ->get("/v1/company/{$cvr}/tax")
                ->throw()
                ->json('data');
        } catch (RequestException $e) {
            return null;
        }
    }

    public function fetchCompaniesByCpr(string $cpr): ?array
    {
        return $this->post('/v1/cvr/search-by-cpr', ['cpr' => $cpr]);
    }

    public function fetchPropertiesByCpr(string $cpr): ?array
    {
        return $this->post('/v1/property-tinglysning/search-by-cpr', ['cpr' => $cpr]);
    }

    public function fetchPersonRoles(string $query): ?array
    {
        return $this->post('/v1/cvr/person-roles', ['name' => $query]);
    }

    public function fetchPersonPropertyPortfolioByCpr(string $cpr): ?array
    {
        return $this->post('/v1/person/property-portfolio', ['cpr' => $cpr]);
    }

    /**
     * Resolve address to property analysis with caching.
     * Merged from MetisInputDetector::resolveAddressAnalysis().
     */
    public function resolveAddressAnalysis(string $address): array
    {
        $cacheKey = 'metis:address_analysis:'.md5($address);

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($address) {
            $parsed = $this->parseAddress($address);

            // Return raw API response (not transformed) — sections need full data
            $result = $this->post('/v1/property/analysis', $parsed);

            if (isset($result['error']) || empty($result['property'] ?? null)) {
                return [];
            }

            return $result;
        });
    }

    public function resolvePropertyComparison(string $query): ?array
    {
        $parsed = $this->parseAddress($query);

        if (! $parsed || empty($parsed['zip'])) {
            return null;
        }

        $address = trim(($parsed['street'] ?? '') . ' ' . ($parsed['number'] ?? ''));
        $postalCode = $parsed['zip'];

        $cacheKey = "metis_comparison_{$postalCode}_{$address}";

        return Cache::remember($cacheKey, 3600, fn () =>
            rescue(fn () => $this->client()
                ->post('property/compare', [
                    'address' => $address,
                    'postal_code' => $postalCode,
                ])->json('data'), null)
        );
    }

    public function parseAddress(string $address): array
    {
        $parts = array_map('trim', explode(',', $address, 2));

        // "Bredgade 40" -> street=Bredgade, number=40
        preg_match('/^(.+?)\s+(\d+\S*)\s*$/', $parts[0], $matches);
        $street = $matches[1] ?? $parts[0];
        $number = $matches[2] ?? '';

        // "1260 København" -> zip=1260
        $zip = '';
        if (isset($parts[1])) {
            preg_match('/(\d{4})/', $parts[1], $zipMatch);
            $zip = $zipMatch[1] ?? '';
        }

        return ['street' => $street, 'number' => $number, 'zip' => $zip];
    }

    protected function get(string $endpoint, array $query = []): ?array
    {
        try {
            return $this->client()
                ->get($endpoint, $query)
                ->throw()
                ->json('data');
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    protected function post(string $endpoint, array $data): array
    {
        try {
            return $this->client()
                ->post($endpoint, $data)
                ->throw()
                ->json('data');
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    /**
     * Debt-search endpoint returns the response shape at the root (not under 'data'),
     * so we bypass the get()/post() helpers which extract that key.
     */
    public function debtSearch(array $filters, ?string $source = null): array
    {
        $request = $this->client();
        if ($source !== null) {
            $request = $request->withHeaders(['X-Search-Source' => $source]);
        }

        try {
            return $request->get('/v1/debt-search', $filters)->throw()->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    public function createDebtSearchCsvLink(array $filters): array
    {
        try {
            return $this->client()
                ->post('/v1/debt-search/export-link', $filters)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    // F1 Debt Alerts — watchlists + alerts endpoints

    public function listWatchlists(): array
    {
        try {
            return $this->client()->get('/v1/watchlists')->throw()->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    public function checkBatch(array $items): array
    {
        try {
            return $this->client()
                ->post('/v1/watchlists/check-batch', ['items' => $items])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    public function createWatchlist(string $type, string $value, ?string $label, array $alertTypes): array
    {
        try {
            return $this->client()->post('/v1/watchlists', [
                'watch_type' => $type,
                'watch_value' => $value,
                'display_label' => $label,
                'alert_types' => $alertTypes,
            ])->throw()->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    public function deleteWatchlist(int $id): array
    {
        try {
            return $this->client()->delete("/v1/watchlists/{$id}")->throw()->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    public function listAlerts(bool $unreadOnly = false, ?string $priority = null, int $page = 1): array
    {
        try {
            return $this->client()->get('/v1/alerts', array_filter([
                'unread_only' => $unreadOnly ? 1 : 0,
                'priority' => $priority,
                'page' => $page,
            ], fn ($v) => $v !== null))->throw()->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    public function markAlertRead(int $alertId): array
    {
        try {
            return $this->client()->patch("/v1/alerts/{$alertId}/read")->throw()->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }
}
