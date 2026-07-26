<?php

namespace TheFountainhead\Metis\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RegistryApi
{
    /**
     * Bounds fetchCompanyInfosPooled()'s Http::pool() fan-out. Without an
     * explicit value, this Laravel version treats null concurrency as
     * UNLIMITED — a graph with ~120 stub-companies would fire every request
     * at once against registry-api instead of trickling through a queue.
     */
    protected const POOL_CONCURRENCY = 6;

    protected function client()
    {
        // F1 pilot — if user has set personal token in session (via /alerts
        // token-input form), use it. Otherwise fall back to shared tenant key.
        // Session token enables per-user data (watchlists, alerts) on a
        // standalone Metis without full sign-up/login plumbing.
        $token = session('metis_user_token') ?: config('metis.registry_api.key');

        return Http::withToken($token)
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

                // Count properties via cached portfolio or quick API check.
                // Kaldet bruger ikke ->throw(), så en fejl-status giver bare
                // json()===null (fanges af ?? 0) — kun ConnectionException
                // kastes. Fang derfor præcis den og ikke \Throwable: en
                // catch-all ville også sluge testenes StrayRequestException,
                // så en omdøbning af endpointet stille ville rapportere 0
                // ejendomme i stedet for at fejle testen.
                try {
                    $propertyCount = $this->client()->timeout(5)
                        ->get("/v1/company/{$cvr}/property-portfolio", ['limit' => 0])
                        ->json('data.portfolio.total_count') ?? 0;
                } catch (ConnectionException $e) {
                    // rescue() rapporterede automatisk; bevar den observability.
                    report($e);

                    $propertyCount = 0;
                }

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
        $cacheKey = $this->companyInfoCacheKey($cvr);

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $response = $this->client()
                ->get("/v1/cvr/company/{$cvr}");

            $company = $response->json('data.company');
        } catch (\Throwable $e) {
            return null;
        }

        // Kun cache et rigtigt svar — en fejl eller manglende data må ikke
        // skygge for friske data i 24t (samme mønster som property-portfolio).
        if ($company) {
            Cache::put($cacheKey, $company, 86400);
        }

        return $company;
    }

    /**
     * Pooled variant af fetchCompanyInfo() til graf-berigelse: slår cache op
     * pr. cvr FØRST, henter kun de manglende samtidigt via Http::pool, og
     * returnerer en map cvr => company-array|null.
     *
     * Uafhængige kald (modsat fetchPropertiesBatch's alt-eller-intet): ét
     * cvr's 500'er eller timeout må ikke koste de andre kortene i grafen —
     * det svarer til hvordan komponenten allerede itererer selskaber
     * enkeltvis via fetchCompanyInfo(), blot samtidigt.
     *
     * Pool-requests kan ikke genbruge en PendingRequest fra client() (poolen
     * bygger sine egne requests via closure), så base-url/token/headers
     * genskabes eksplicit på hver pool-request.
     */
    public function fetchCompanyInfosPooled(array $cvrs): array
    {
        if (empty($cvrs)) {
            return [];
        }

        $cvrs = array_values(array_unique($cvrs));

        $results = [];
        $missing = [];

        foreach ($cvrs as $cvr) {
            $cacheKey = $this->companyInfoCacheKey($cvr);

            if (! is_null($cached = Cache::get($cacheKey))) {
                $results[$cvr] = $cached;
            } else {
                $missing[] = $cvr;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        $token = session('metis_user_token') ?: config('metis.registry_api.key');
        $baseUrl = config('metis.registry_api.url');

        $responses = Http::pool(fn ($pool) => collect($missing)
            ->map(fn ($cvr) => $pool->as($cvr)
                ->withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->baseUrl($baseUrl)
                ->get("/v1/cvr/company/{$cvr}"))
            ->all(), concurrency: self::POOL_CONCURRENCY);

        foreach ($missing as $cvr) {
            $response = $responses[$cvr] ?? null;

            try {
                $company = $response instanceof \Illuminate\Http\Client\Response && $response->successful()
                    ? $response->json('data.company')
                    : null;
            } catch (\Throwable $e) {
                $company = null;
            }

            if ($company) {
                Cache::put($this->companyInfoCacheKey($cvr), $company, 86400);
            }

            $results[$cvr] = $company;
        }

        return $results;
    }

    protected function companyInfoCacheKey(string $cvr): string
    {
        return "metis:company_info:{$cvr}";
    }

    public function fetchCompanyStructure(string $cvr): array
    {
        return $this->post('/v1/cvr/company-structure', ['cvr' => $cvr]) ?? [];
    }

    /**
     * Cachet variant af fetchCompanyStructure() — bruges KUN når enrichment
     * ikke kører (se komponentens $enriching-flag). Mens enrichment kører
     * skal den ucachede fetchCompanyStructure() bruges, ellers fryser
     * datter-væksten i 5 minutter.
     */
    public function fetchCompanyStructureCached(string $cvr): array
    {
        $cacheKey = "metis:company_structure:{$cvr}";

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $structure = $this->fetchCompanyStructure($cvr);

        // Cache kun et ikke-tomt svar — se noten ved fetchCompanyPropertyPortfolio.
        if (! empty($structure)) {
            Cache::put($cacheKey, $structure, 300);
        }

        return $structure;
    }

    /**
     * Batch-opslag af ejendomme via matrikel-id. Chunker i grupper à 200
     * (backend-grænse) og fladgør 'data'-listerne fra hver chunk til én liste.
     *
     * Alt-eller-intet: post()-hjælperen returnerer ['error' => ..., 'status' => ...]
     * ved HTTP-fejl (aldrig null), så et fejlende chunk kan ikke bare
     * flatMap'es ind — det ville blande fejl-strenge ind mellem gyldige
     * properties. Fejler ét chunk, returnér null for hele kaldet: Task 7's
     * fejl-flow behandler null som "berigelse fejlede", mens et stille
     * delvist resultat ville give ejendoms-noder uden anvendelse uden
     * forklaring.
     */
    public function fetchPropertiesBatch(array $matrikelIds): ?array
    {
        if (empty($matrikelIds)) {
            return [];
        }

        $properties = [];

        foreach (collect($matrikelIds)->chunk(200) as $chunk) {
            $response = $this->post('/v1/properties/batch', [
                'matrikel_ids' => $chunk->values()->all(),
            ]);

            if (! is_array($response) || isset($response['error'])) {
                return null;
            }

            array_push($properties, ...$response);
        }

        return $properties;
    }

    /**
     * Aktiepost-relationer (begge retninger) — SEPARAT endpoint fra structure,
     * så relations aldrig blandes i ejendoms-/gæld-aggregering (Option B).
     */
    public function fetchCompanyRelations(string $cvr): array
    {
        return $this->post('/v1/cvr/company-relations', ['cvr' => $cvr]) ?? [];
    }

    public function fetchFundingHistory(string $cvr): ?array
    {
        try {
            return $this->client()
                ->get("/v1/company/{$cvr}/funding-history")
                ->throw()
                ->json('data');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function fetchCompanyPropertyPortfolio(string $cvr, int $limit = 25, int $offset = 0): ?array
    {
        $cacheKey = "metis:company_property_portfolio:{$cvr}:{$limit}:{$offset}";

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $result = $this->client()
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

        // Cache kun færdige porteføljer. Fejl og 'building'-svar må ikke
        // skygge for friske data i 5 min mens backend-jobbet bygger cachen.
        if (! empty($result['portfolio']['properties'])) {
            Cache::put($cacheKey, $result, now()->addMinutes(5));
        }

        return $result;
    }

    /**
     * Fetch tinglysning-overview for a company (root + descendant koncerntræ).
     *
     * Returns the raw API shape per spec:
     * [
     *   'company' => ['cvr' => '...', 'name' => '...'],
     *   'tree_meta' => [...],
     *   'tier_breakdown' => [...],
     *   'mortgages_added' => [...],
     *   'streaming' => ['complete' => bool, 'cursor' => '...', 'total_expected' => N, 'delivered_so_far' => M],
     * ]
     *
     * Returns null on transport/HTTP failure (caller renders error-state).
     *
     * @param  array<string,mixed>  $filters  status, mortgage_types[], min_amount, max_amount, sort, tree_depth
     * @param  string|null  $cursor  Opaque cursor for delta-streaming continuation
     */
    public function fetchCompanyTinglysningOverview(
        string $cvr,
        array $filters = [],
        ?string $cursor = null,
    ): ?array {
        $cacheKey = "metis:tinglysning_overview:{$cvr}:" . md5(json_encode([$filters, $cursor]));

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($cvr, $filters, $cursor) {
            $query = array_filter([
                ...$filters,
                'cursor' => $cursor,
            ], fn ($v) => $v !== null && $v !== '');

            try {
                return $this->client()
                    ->timeout(15)
                    ->get("/v1/companies/{$cvr}/tinglysning-overview", $query)
                    ->throw()
                    ->json();
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

    /**
     * F6: Fetch comparable sales for a given BFE.
     */
    public function fetchSimilarSales(string $bfe, array $opts = []): ?array
    {
        try {
            return $this->client()
                ->get("/v1/properties/{$bfe}/similar-sales", array_filter([
                    'radius_km' => $opts['radius_km'] ?? null,
                    'area_pct' => $opts['area_pct'] ?? null,
                    'months_back' => $opts['months_back'] ?? null,
                ]))
                ->throw()
                ->json('data');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Aggregated property portfolio joined across all companies where this
     * person is Reelle ejere / EJERREGISTER. Server-side cached 6h per name.
     * Slow on first call (5-15s for ~10 companies), instant after cache.
     */
    public function fetchPersonPropertyPortfolio(string $name): ?array
    {
        try {
            return $this->client()
                ->timeout(60)
                ->post('/v1/cvr/person-property-portfolio', ['name' => $name])
                ->throw()
                ->json('data');
        } catch (RequestException $e) {
            return null;
        }
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

        return Cache::remember($cacheKey, 3600, function () use ($address, $postalCode) {
            // Kun ConnectionException kan kastes her — se noten ved
            // property-portfolio ovenfor.
            try {
                return $this->client()
                    ->post('property/compare', [
                        'address' => $address,
                        'postal_code' => $postalCode,
                    ])->json('data');
            } catch (ConnectionException $e) {
                report($e);

                return null;
            }
        });
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

    /**
     * "Udforsk" — geography-driven property search. POST (not GET) because the
     * polygon filter is a nested array. Geo filter is required server-side.
     */
    public function propertyExplore(array $filters, ?string $source = null): array
    {
        $request = $this->client();
        if ($source !== null) {
            $request = $request->withHeaders(['X-Search-Source' => $source]);
        }

        try {
            return $request->post('/v1/property-explore', $filters)->throw()->json();
        } catch (RequestException $e) {
            return ['error' => $e->getMessage(), 'status' => $e->getCode()];
        }
    }

    public function createPropertyExploreCsvLink(array $filters): array
    {
        try {
            return $this->client()
                ->post('/v1/property-explore/export-link', $filters)
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

    /**
     * F5 disambiguation: search CVR-deltagere by name, return candidates with
     * enhedsnummer + address + companies+roles for user to pick from.
     *
     * @return array{candidates: array, total: int}|array{error: string}
     */
    public function disambiguatePerson(string $name, int $limit = 20): array
    {
        try {
            return $this->client()
                ->get('/v1/cvr/person-disambiguate', ['name' => $name, 'limit' => $limit])
                ->throw()
                ->json('data');
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

    public function getAlert(int $id): ?array
    {
        try {
            return $this->client()->get("/v1/alerts/{$id}")->throw()->json('data');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
