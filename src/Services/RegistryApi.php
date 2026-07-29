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

    /**
     * Bounds fetchCompanyStructuresPooled()'s Http::pool() fan-out. Lower
     * than POOL_CONCURRENCY: company-structure is a heavier endpoint
     * (koncerntræ-walk) than the plain company-info lookup, so this trickles
     * more conservatively against registry-api.
     */
    protected const STRUCTURE_POOL_CONCURRENCY = 3;

    protected function client()
    {
        // F1 pilot — if user has set personal token in session (via /alerts
        // token-input form), use it. Otherwise fall back to shared tenant key.
        // Session token enables per-user data (watchlists, alerts) on a
        // standalone Metis without full sign-up/login plumbing.
        $token = session('metis_user_token') ?: config('metis.registry_api.key');

        // Transport-hærdning (Flare 9097433, 27/7-26): registry-api's kolde
        // search-by-cpr-sti har et server-worst-case på ~40s (to kædede
        // gov-API'er synkront) — 30s klient-timeout timede ud BY CONSTRUCTION.
        // Mønstret her er propageret fra m2softs RegistryApiService (bevist
        // mod SAMME backend): 60s budget, 10s connect, én retry KUN på
        // transport-fejl (ConnectionException) — aldrig på modtaget 4xx/5xx,
        // som er svar, ikke transportstøj. NB retry($times) er TOTALE forsøg,
        // ikke antal retries — retry(1) er en no-op (empirisk verificeret;
        // m2softs retry(1, ...) har samme fælde). retry(throw: true) er
        // bevidst: throw:false gør IKKE kaldet kaste-frit for
        // ConnectionException (ingen Response at returnere) — get()/post()
        // fanger i stedet. throw: false er PÅKRÆVET af to grunde: (1) uden
        // den ville retry-laget kaste RequestException på ENHVER fejl-status
        // selv i kald uden ->throw() (fx searchPersonByName's 500→0-fallback,
        // som ville knække); (2) den gør IKKE ConnectionException kaste-fri
        // (ingen Response at returnere ved exhaustion) — hvilket her er
        // præcis den ønskede asymmetri: status-fejl opfører sig som før,
        // transport-fejl når stadig get()/post()'s catch.
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(10)
            ->retry(2, 2000, fn (\Exception $e) => $e instanceof ConnectionException, throw: false)
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

    /**
     * Cached variant, mirroring fetchCompaniesByCprCached()'s contract (5 min
     * TTL, failures NEVER cached).
     *
     * PersonStructure calls this from mount AND from rehydrateBeforeRebuild(),
     * which every poll tick, chip toggle and expand runs through — so the
     * uncached version refired an identical POST roughly 10-12 times on an
     * ordinary page view, for a relationship set that cannot change between
     * them.
     *
     * The key is the SET of cvrs, sorted: the caller derives the list from
     * graph order, which shifts as the graph grows, and an order-sensitive key
     * would miss on every reordering — cache-missing precisely when the page
     * is busiest. sha1 keeps the key bounded no matter how many cvrs a person
     * has (the cap is 20, so the raw list would otherwise run to ~180 chars).
     *
     * A failure must not be cached: post() returns ['error' => …] rather than
     * throwing, and in PersonStructure a cross-ownership failure fails the
     * WHOLE skeleton — caching it would leave the retry button dead for the
     * full TTL.
     */
    public function fetchCrossOwnershipCached(array $cvrs): array
    {
        $sorted = collect($cvrs)->map(fn ($cvr) => (string) $cvr)->unique()->sort()->values()->all();
        $cacheKey = 'metis:cross_ownership:'.sha1(implode(',', $sorted));

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $result = $this->fetchCrossOwnership($cvrs);

        if (! isset($result['error'])) {
            Cache::put($cacheKey, $result, 300);
        }

        return $result;
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

    /**
     * CACHE-ONLY variant of fetchCompanyInfosPooled(): returns cvr => company
     * for the cvrs whose 24h company-info cache is still warm, and simply
     * OMITS the rest. Issues no HTTP request under any circumstance.
     *
     * For recovery paths running inside INTERACTIVE requests (PersonStructure's
     * chip toggle / expand, via recoverEnrichmentResults()). Those must not
     * turn a click into a pooled network pass whose size grows with the number
     * of companies in the graph — a cold cache would make an interaction that
     * is normally instant hang on a fan-out of upstream calls. The caller
     * treats an incomplete map as "not recoverable cheaply" and hands the phase
     * back to the poll loop, which fetches it properly a tick later.
     *
     * @return array<string, array>
     */
    public function fetchCompanyInfosCached(array $cvrs): array
    {
        $results = [];

        foreach (array_unique($cvrs) as $cvr) {
            $cached = Cache::get($this->companyInfoCacheKey((string) $cvr));

            if (! is_null($cached)) {
                $results[(string) $cvr] = $cached;
            }
        }

        return $results;
    }

    /**
     * 🚨 DELIBERATELY TENANT-NEUTRAL — not an oversight, and not to be "fixed"
     * by adding the token to the key without reading this first.
     *
     * The key carries no token/user/session component, so every caller shares
     * one entry per cvr. That is correct because company-info is CVR REGISTER
     * data: public, identical for every caller, and not derived from who asked.
     * The token is a QUOTA IDENTITY (who is billed, who is rate-limited), not
     * an authorisation scope — two tokens asking about the same cvr are
     * entitled to byte-identical answers.
     *
     * Namespacing per token would multiply the cache by the number of tokens
     * and turn a warm shared cache into N cold ones, for no privacy gain.
     *
     * WHEN TO REVISIT: the moment registry-api returns anything token-SCOPED on
     * this endpoint — a per-customer note, an entitlement flag, a field one
     * tenant sees and another does not. At that point the shared entry leaks
     * one tenant's view to another and the key MUST gain a token component.
     * There is a test pinning this decision so the change is a conversation
     * rather than an accident.
     */
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
        if (! is_null($cached = Cache::get(self::structureCacheKey($cvr)))) {
            return $cached;
        }

        $structure = $this->fetchCompanyStructure($cvr);
        $this->cacheStructure($cvr, $structure);

        return $structure;
    }

    /**
     * CACHE-ONLY variant of fetchCompanyStructureCached() — whose name promises
     * a cache but which falls through to a real POST on a miss. This one never
     * does: a miss returns null and the caller decides.
     *
     * For recovery paths inside INTERACTIVE requests; see
     * fetchCompanyInfosCached() for the full rationale.
     */
    public function fetchCompanyStructureFromCache(string $cvr): ?array
    {
        return Cache::get(self::structureCacheKey($cvr));
    }

    /**
     * 🚨 Tenant-neutral by the same deliberate decision as
     * companyInfoCacheKey() — see that docblock for the full reasoning and for
     * the condition under which this must change (any token-SCOPED field
     * appearing on the endpoint).
     */
    protected static function structureCacheKey(string $cvr): string
    {
        return "metis:company_structure:{$cvr}";
    }

    /**
     * Cache kun et ikke-tomt svar — se noten ved fetchCompanyPropertyPortfolio:
     * en fejl (eller et tomt svar) må ikke skygge for friske data i 5 minutter.
     * Delt af den cachede og den pooled'e henter, så key, TTL og tom-reglen kun
     * findes ét sted.
     */
    protected function cacheStructure(string $cvr, ?array $structure): void
    {
        if (! empty($structure)) {
            Cache::put(self::structureCacheKey($cvr), $structure, 300);
        }
    }

    /**
     * Pooled variant af fetchCompanyStructure() til fase 2's graf-udvidelse:
     * hvert cvr hentes samtidigt via Http::pool, præcis én gang pr. kald.
     *
     * Cache-kontrakten er ASYMMETRISK og bevidst: LÆSER aldrig (fase 2 skal
     * have hvert cvr friskt), men SKRIVER via cacheStructure(), så den
     * efterfølgende rehydrering bliver et cache-hit. Uden den skrivning ramte
     * PersonStructures per-tick-gendannelse netværket igen for hvert allerede
     * hentet cvr — ~20 ekstra POSTs pr. 2-sekunders tick ved first-level-loftet.
     *
     * Samme uafhængigheds-garanti som fetchCompanyInfosPooled(): ét cvr's
     * fejl giver null for det cvr, aldrig en exception ud til kalderen.
     */
    public function fetchCompanyStructuresPooled(array $cvrs): array
    {
        if (empty($cvrs)) {
            return [];
        }

        $cvrs = array_values(array_unique($cvrs));

        $token = session('metis_user_token') ?: config('metis.registry_api.key');
        $baseUrl = config('metis.registry_api.url');

        $responses = Http::pool(fn ($pool) => collect($cvrs)
            ->map(fn ($cvr) => $pool->as($cvr)
                ->withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->baseUrl($baseUrl)
                ->post('/v1/cvr/company-structure', ['cvr' => $cvr]))
            ->all(), concurrency: self::STRUCTURE_POOL_CONCURRENCY);

        $results = [];

        foreach ($cvrs as $cvr) {
            $response = $responses[$cvr] ?? null;

            try {
                $results[$cvr] = $response instanceof \Illuminate\Http\Client\Response && $response->successful()
                    ? $response->json('data')
                    : null;
            } catch (\Throwable $e) {
                $results[$cvr] = null;
            }

            $this->cacheStructure((string) $cvr, $results[$cvr]);
        }

        return $results;
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

    /**
     * CACHE-ONLY variant of fetchCompanyPropertyPortfolio(): returns the cached
     * payload if the 5-min entry is still warm, and null otherwise. Issues no
     * HTTP request under any circumstance.
     *
     * Sibling of fetchCompanyInfosCached(), and there for the same reason: the
     * recovery paths run inside INTERACTIVE requests (PersonStructure's chip
     * toggle / expand), where a cache miss must cost nothing. The caller
     * downgrades the cvr and lets the poll loop fetch it properly a tick later
     * — see recoverPropertyResults().
     *
     * Shares the key with the fetching variant rather than deriving its own, so
     * the two cannot drift apart over a limit/offset default.
     */
    public function fetchCompanyPropertyPortfolioCached(string $cvr, int $limit = 25, int $offset = 0): ?array
    {
        return Cache::get(self::propertyPortfolioCacheKey($cvr, $limit, $offset));
    }

    protected static function propertyPortfolioCacheKey(string $cvr, int $limit, int $offset): string
    {
        return "metis:company_property_portfolio:{$cvr}:{$limit}:{$offset}";
    }

    public function fetchCompanyPropertyPortfolio(string $cvr, int $limit = 25, int $offset = 0): ?array
    {
        $cacheKey = self::propertyPortfolioCacheKey($cvr, $limit, $offset);

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

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $query = array_filter([
            ...$filters,
            'cursor' => $cursor,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $result = $this->client()
                ->timeout(15)
                ->get("/v1/companies/{$cvr}/tinglysning-overview", $query)
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            // Cach ALDRIG en fejl: en retry inden for TTL'en ville få det cachede
            // null tilbage og gøre knappen virkningsløs.
            return null;
        }

        Cache::put($cacheKey, $result, now()->addMinutes(5));

        return $result;
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

    /**
     * Companies for a person looked up by NAME (no CPR) — same shape as the
     * CPR path so PersonStructure can classify() it unchanged.
     *
     * post() never returns null itself: a RequestException is caught by
     * errorFrom() and turned into ['error' => 'upstream_error', 'status' =>
     * $e->getCode()], and a ConnectionException into ['error' =>
     * 'upstream_error', 'status' => 0] via transportErrorFrom(). Those two
     * failure modes mean different things to the caller, so they're kept
     * distinct here rather than both collapsing to one shape:
     *
     * - status 404 ⇒ the participant genuinely doesn't exist in CVR. This is
     *   NOT a failure — it's a real, settled answer of zero companies. Mapped
     *   to ['companies' => []] so the caller reaches its ordinary EMPTY state
     *   (skeletonStatus = 'empty') rather than its FAILED state. Returning
     *   null here previously made PersonStructure::attempt() normalise it to
     *   a failure, so a name that simply isn't in CVR rendered "Selskabsrelationerne
     *   kunne ikke hentes." with a retry button that could never succeed —
     *   the exact "findes ikke ligner kunne ikke hentes" bug this guards
     *   against now.
     * - any other error (incl. status 0 for a transport failure) ⇒ returned
     *   as-is, the ['error' => ...] shape. The caller must NOT read this as
     *   "no companies" — a transport failure is genuinely retryable, unlike a
     *   404, so it must keep failing PersonStructure::attempt() into 'failed'.
     */
    public function fetchCompaniesByName(string $name): ?array
    {
        $result = $this->post('/v1/cvr/person-companies-by-name', ['name' => $name]);

        // 'status' alone is not a safe discriminator: it's already a business
        // field elsewhere in this class (company['status'] === 'NORMAL' in
        // searchPersonByName()/fetchCompany()), and nothing stops a success
        // payload from carrying a 'status' key of its own. Only errorFrom()
        // and transportErrorFrom() set 'error' — gate on that first.
        if (isset($result['error']) && ($result['status'] ?? null) === 404) {
            return ['companies' => []];
        }

        return $result;
    }

    /**
     * Cachet variant af fetchCompaniesByCpr() — nøglen hashes (sha1) så et
     * rå CPR-nummer aldrig havner i cache-nøglen eller -loggen. 5 min TTL:
     * lang nok til at dæmpe gentagne opslag under samme graf-udvidelse, kort
     * nok til at nye selskabsregistreringer dukker op hurtigt. Fejl caches
     * ALDRIG — se noten ved fetchCompanyInfo().
     */
    public function fetchCompaniesByCprCached(string $cpr): ?array
    {
        $cacheKey = 'metis:companies_by_cpr:'.sha1($cpr);

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $result = $this->fetchCompaniesByCpr($cpr);

        // post()'s catch-block returnerer aldrig null ved en RequestException
        // — den giver et ['error' => ..., 'status' => ...]-array. Den fejlform
        // skal behandles som "fejlede" på samme måde som et rent null-svar,
        // ellers cacher vi en 500'er i 5 minutter.
        if (! is_null($result) && ! isset($result['error'])) {
            Cache::put($cacheKey, $result, 300);
        }

        return $result;
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
            $response = $this->client()
                ->timeout(60)
                ->post('/v1/cvr/person-property-portfolio', ['name' => $name]);

            // Tjek status FØR ->throw(): 404 er et svar vi vil behandle,
            // ikke en fejl vi vil kaste på.
            //
            // 404 betyder at NAVNEOPSLAGET ikke fandt en person
            // (CvrController::personPropertyPortfolioByName: searchPersonRolesByName()
            // gav falsy). Det er "vi slog ikke op" — IKKE "personen har ingen
            // ejendomme". Returnér derfor en egen markør, ikke en tom
            // portefølje: en tom portefølje ville få viewet til at påstå
            // fravær, og knappen vises kun når vi ALLEREDE har skrevet
            // "N ejendomme via M selskaber" paa skærmen. Det ville være en falsk
            // autoritativ benægtelse — den værste fejlmodus i due diligence.
            //
            if ($response->status() === 404) {
                return ['not_found' => true];
            }

            return $response->throw()->json('data');
        } catch (ConnectionException|RequestException $e) {
            return null;   // ægte fejl — konsumenten må tilbyde retry
        }
    }

    public function fetchPersonPropertyPortfolioByCpr(string $cpr): ?array
    {
        return $this->post('/v1/person/property-portfolio', ['cpr' => $cpr]);
    }

    /**
     * Cachet variant af fetchPersonPropertyPortfolioByCpr() — spejler
     * fetchCompaniesByCprCached() præcist: nøglen hashes (sha1) så et rå
     * CPR-nummer aldrig havner i cache-nøglen eller -loggen, 300s TTL, og
     * fejl caches ALDRIG. post()'s catch-block returnerer aldrig null ved en
     * RequestException — den giver et ['error' => ..., 'status' => ...]-array
     * — den fejlform skal behandles som "fejlede" på samme måde som et rent
     * null-svar, ellers cacher vi en 500'er (eller transportfejl) i 5 minutter.
     */
    public function fetchPersonPropertyPortfolioByCprCached(string $cpr): ?array
    {
        $cacheKey = self::personPropertyPortfolioCacheKey($cpr);

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $result = $this->fetchPersonPropertyPortfolioByCpr($cpr);

        if (! is_null($result) && ! isset($result['error'])) {
            Cache::put($cacheKey, $result, 300);
        }

        return $result;
    }

    /**
     * CACHE-ONLY variant, the exact counterpart of
     * fetchCompanyStructureFromCache(): a miss returns null and the caller
     * decides, rather than falling through to the real POST the way the
     * ...Cached() method above does.
     *
     * For recovery paths that run inside INTERACTIVE requests (a chip toggle,
     * an expand). This endpoint is the most expensive one in the package —
     * 5-15s on a cold call — so a recovery pass allowed to fall through would
     * make a single chip click hang for that long, which is the whole reason
     * the cache-only/never-fetch split exists.
     */
    public function fetchPersonPropertyPortfolioByCprFromCache(string $cpr): ?array
    {
        return Cache::get(self::personPropertyPortfolioCacheKey($cpr));
    }

    /** sha1'd so a raw CPR never lands in a cache key, a log line or a dump. */
    protected static function personPropertyPortfolioCacheKey(string $cpr): string
    {
        return 'metis:person_property_portfolio:'.sha1($cpr);
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

    /**
     * 🚨 The error VALUE is a constant, never $e->getMessage(). RequestException
     * builds its message as "HTTP request returned status code N" plus the
     * upstream RESPONSE BODY (truncated to 120 chars) — and registry-api echoes
     * request input into some of its error bodies. On the CPR path that input is
     * the CPR, so getMessage() quietly turned every failed lookup into a return
     * value carrying the CPR onward to whatever logged, cached or rendered it.
     *
     * Nothing is lost: report() sends the REAL exception down the exception
     * channel, where the host-side Flare scrubber censors it. And no caller ever
     * read the string — every consumer in src/ isset()-checks the key and
     * substitutes its own user-facing message (grepped across the package),
     * which is exactly what makes a constant safe here.
     *
     * Used by EVERY catch site in this class, not just the two the CPR path
     * happens to run through: the shape is identical at all 13, and a helper
     * only half the file uses is an invitation to reintroduce the leak in the
     * next endpoint someone adds.
     */
    protected function errorFrom(RequestException $e): array
    {
        report($e);

        return ['error' => 'upstream_error', 'status' => $e->getCode()];
    }

    /**
     * ConnectionException er en SØSKENDE til RequestException (extends
     * Exception, ikke HttpClientException) — en timeout gik derfor LIGE
     * IGENNEM de her helpers og ramte 15 kaldsteder som uhåndteret exception
     * (Flare 9097433; PR #103 lukkede kun de 2 kaldsteder der selv omslutter
     * HTTP og stillede aldrig dette nabospørgsmål). Fanges nu til samme
     * bagudkompatible ['error' => ...]-shape som alle 13 forbrugere allerede
     * isset()-guarder på; status 0 = "intet svar modtaget".
     */
    protected function get(string $endpoint, array $query = []): ?array
    {
        try {
            return $this->client()
                ->get($endpoint, $query)
                ->throw()
                ->json('data');
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    /**
     * Som errorFrom(): report() bevarer observability (en bar catch gør det
     * IKKE selv — det var rescue()'s eneste dyd), beskeden propagerer ALDRIG
     * (cURL-tekstens varierende ms ville splitte Flare-buckets, og den kan
     * bære upstream-echo af input).
     */
    protected function transportErrorFrom(ConnectionException $e): array
    {
        report($e);

        return ['error' => 'upstream_error', 'status' => 0];
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    // F1 Debt Alerts — watchlists + alerts endpoints

    public function listWatchlists(): array
    {
        try {
            return $this->client()->get('/v1/watchlists')->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function deleteWatchlist(int $id): array
    {
        try {
            return $this->client()->delete("/v1/watchlists/{$id}")->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function markAlertRead(int $alertId): array
    {
        try {
            return $this->client()->patch("/v1/alerts/{$alertId}/read")->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
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
