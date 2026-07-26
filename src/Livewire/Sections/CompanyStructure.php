<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\BbrUsageCategory;
use TheFountainhead\Metis\Services\OwnershipGraphBuilder;
use TheFountainhead\Metis\Services\RegistryApi;

class CompanyStructure extends MetisSection
{
    /**
     * Loft for antal 'building'-forsøg (Task 8's blade re-poller loadProperties
     * med stigende delay via $propertiesAttempts). Uden loft ville en portefølje
     * der aldrig færdiggøres (backend-fejl uden 500, blot evigt 'building')
     * poll'e for evigt — efter loftet slår vi om til 'failed' så bladen viser
     * en retry-knap i stedet for en uendelig spinner.
     */
    protected const MAX_PROPERTIES_ATTEMPTS = 8;

    /**
     * Historical-owner data only — the ownership tree (built from ancestors
     * inside $structureData, via the builder) is the single source for
     * CURRENT owners. $owners still feeds the Blade's "Historical" block.
     */
    public array $owners = [];
    public bool $enriching = false;
    public int $companiesFound = 0;
    public ?string $companyName = null;

    /** @var 'pending'|'loaded'|'failed' */
    public string $enrichmentStatus = 'pending';

    /**
     * The flat {nodes, edges} graph model, kept as public state so the Alpine
     * graph can `$wire.$watch('graphModel', …)` it: an enrichment poll deepens
     * the chain, this property changes, and the graph re-lays-out in place
     * WITHOUT a wire:ignore re-mount (so the user's zoom/pan survives). Every
     * code path (mount, poll, expand, property-load) REBUILDS this through
     * OwnershipGraphBuilder — nothing ever appends to it directly.
     */
    public array $graphModel = ['nodes' => [], 'edges' => []];

    /** 'sub:<cvr>' / 'props:<cvr>' node ids the user has expanded past the cap. */
    public array $expandedNodeIds = [];

    /** @var 'pending'|'building'|'loaded'|'empty'|'failed' */
    public string $propertiesStatus = 'pending';

    public int $propertiesAttempts = 0;

    /**
     * Builder input, held as PROTECTED state (not part of the Livewire wire
     * payload — never re-sent to/from the browser). Rebuilt from the API
     * (cached where possible) whenever it's empty at the start of an action,
     * because protected properties do NOT survive Livewire hydration across
     * requests: each request gets a fresh component instance built only from
     * public state, so $structureData/$propertyData start empty again unless
     * explicitly re-fetched.
     */
    protected array $structureData = [];

    protected array $propertyData = ['list' => [], 'usage' => []];

    /**
     * Builder enrichment input (Task 3) — same PROTECTED-state caveat as
     * $structureData/$propertyData above: lost on hydration, rehydrated in
     * rehydrateBeforeRebuild() from enrichmentStatus === 'loaded'.
     */
    protected array $enrichmentData = ['companies' => [], 'properties' => []];

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
        $this->structureData = $result;
        $this->owners = $result['owners'] ?? [];
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
        // Fallback-loopets pr.-ejer fetchCompanyInfo-kald er billige via Task 6's
        // 24t-cache — loopet BEHOLDES (det føder historical-owners-visningen),
        // men wrappet i det eksisterende rescue-mønster.
        foreach ($this->owners as $i => $owner) {
            if (($owner['is_company'] ?? false) && ($owner['cvr'] ?? null)) {
                $parentInfo = rescue(fn () => $api->fetchCompanyInfo($owner['cvr']));
                if ($parentInfo) {
                    $this->owners[$i]['parent_owners'] = $parentInfo['owners'] ?? [];
                }
            }
        }

        $status = rescue(fn () => $api->getEnrichmentStatus($query));
        $this->enriching = in_array($status['status'] ?? '', ['pending', 'running']);
        $this->companiesFound = $status['companies_found'] ?? 0;

        $this->rebuild();
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
            $this->rehydrateBeforeRebuild();
            $this->rebuild();
        }
    }

    /**
     * nodeId = 'sub:<cvr>' or 'props:<cvr>' (see OwnershipGraphBuilder). Lifts
     * the subsidiary-depth or property-per-company cap for that node so the
     * next rebuild reveals what was hidden behind it.
     */
    public function expandNode(string $nodeId): void
    {
        if (! str_starts_with($nodeId, 'sub:') && ! str_starts_with($nodeId, 'props:')) {
            return;
        }
        if (! in_array($nodeId, $this->expandedNodeIds, true)) {
            $this->expandedNodeIds[] = $nodeId;
        }
        $this->rehydrateBeforeRebuild();
        $this->rebuild();
    }

    public function loadProperties(): void
    {
        $this->rehydrateBeforeRebuild();

        // limit: 500 matches CompanyOverview.php's call — same cache key, so
        // large portfolios reuse its warm cache instead of paging in the API's
        // default 25 (which would make the node-cap/expand counts a lie on
        // big koncerner: e.g. JEUDAN has 649 properties).
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($this->query, limit: 500), null);
        $portfolio = $result['portfolio'] ?? null;

        if ($portfolio === null) {
            $this->propertiesStatus = 'failed';

            return;
        }

        $list = $portfolio['properties'] ?? [];
        $count = $portfolio['property_count'] ?? $portfolio['total_count'] ?? 0;

        if ($list === [] && $count > 0) {
            $this->propertiesAttempts++;

            // Loft nået: porteføljen bliver aldrig færdig (eller backend hænger).
            // 'failed' i stedet for evig 'building' giver bladen en retry-knap
            // (som nulstiller $propertiesAttempts) i stedet for en uendelig spinner.
            // Ellers: backend bygger stadig (verificeret prod-adfærd: første kald
            // tomt, andet fuldt) — bladen re-forsøger m. stigende delay.
            $this->propertiesStatus = $this->propertiesAttempts >= self::MAX_PROPERTIES_ATTEMPTS ? 'failed' : 'building';

            return;
        }

        if ($list === []) {
            $this->propertiesStatus = 'empty';

            return;
        }

        $this->propertyData = ['list' => $list, 'usage' => $this->usageMapFor($list)];
        $this->propertiesStatus = 'loaded';
        $this->rebuild();
    }

    public function retryProperties(): void
    {
        $this->propertiesStatus = 'pending';
        $this->propertiesAttempts = 0;
        $this->loadProperties();
    }

    /**
     * Fase 2a.2: fetches per-company financials/contact/etc (pooled) and the
     * full per-property batch map (usage + latest sale + valuation), then
     * rebuilds so OwnershipGraphBuilder can attach cards/signals to nodes.
     *
     * Gated: a no-op while $enriching is true (poll-payload hensyn — the
     * subsidiary tree is still growing, so enriching it now would be wasted
     * work on cvr's the next poll may prune/replace). The existing enrichment
     * poll-completion path (pollForUpdates, when it flips $enriching to
     * false) calls this at the end so it fires exactly once the tree settles.
     *
     * Pool-delfejl (fetchCompanyInfosPooled): individual cvr's are null in
     * the map — those nodes simply get no card (handled entirely by the
     * builder's `isset($companies[$cvr])` check). A total exception (the
     * pooled call itself throwing, e.g. connection refused) is the only path
     * that flips this to 'failed' — reusing the same swallow-and-flag shape
     * as loadProperties()'s 'failed' branch.
     */
    public function loadEnrichment(): void
    {
        if ($this->enriching) {
            return;
        }

        $this->rehydrateBeforeRebuild();

        try {
            $this->fetchEnrichmentData();
        } catch (\Throwable $e) {
            $this->enrichmentStatus = 'failed';

            return;
        }

        $this->enrichmentStatus = 'loaded';
        $this->rebuild();
    }

    /**
     * Reduces a company-info payload to the builder's flat enrichment shape.
     * financials arrives newest-first from the API (CompanyOverview.php's
     * assumption) but is NOT trusted blindly here — sorted explicitly by
     * `year` so an unsorted/out-of-order payload still resolves to the
     * actual latest fiscal year, not just array index 0.
     */
    protected function companyEnrichmentFromInfo(array $company): array
    {
        $latest = collect($company['financials'] ?? [])->sortByDesc('year')->first();

        return [
            'equity' => $latest['equity'] ?? null,
            'result' => $latest['profit_loss'] ?? null,
            'fiscal_year' => $latest['year'] ?? null,
            'employees' => $company['employees'] ?? null,
            'website' => data_get($company, 'contact.website'),
            'founded_date' => $company['founded_date'] ?? null,
            'industry' => $company['industry'] ?? null,
        ];
    }

    /**
     * matrikel_id => primær anvendelses-label via properties/batch + BbrUsageCategory.
     * En NULL fra fetchPropertiesBatch (ét chunk fejlede, alt-eller-intet) er
     * en batch-fejl — IKKE en portfolio-fejl: ejendommene vises stadig, blot
     * uden anvendelses-række. propertiesStatus forbliver 'loaded'.
     */
    protected function usageMapFor(array $properties): array
    {
        $ids = collect($properties)->pluck('matrikel_id')->filter()->map(fn ($m) => (string) $m)->unique()->values()->all();
        $batch = rescue(fn () => app(RegistryApi::class)->fetchPropertiesBatch($ids), null) ?? [];

        return collect($this->propertyEnrichmentFromBatch($batch))->map(fn ($entry) => $entry['usage'] ?? null)->all();
    }

    /**
     * Full per-property enrichment map from a properties/batch response:
     * usage (via BbrUsageCategory, same primary-building selection as the
     * former usageMapFor()) + latest_transaction date/price + valuation.
     * Streetview URLs are NOT built here — they need portfolio lat/lng,
     * which this batch response does not carry; loadEnrichment() layers
     * those in afterwards, keyed by the same matrikel_id.
     */
    protected function propertyEnrichmentFromBatch(array $batch): array
    {
        return collect($batch)->mapWithKeys(function ($p) {
            $buildings = collect($p['bbr']['buildings'] ?? []);
            // Primær bygning: største areal blandt ikke-småbygninger (9xx-koder =
            // garager/udhuse); fallback = største uanset kode.
            $primary = $buildings->filter(fn ($b) => (int) ($b['usage'] ?? 0) < 900)->sortByDesc('total_area')->first()
                ?? $buildings->sortByDesc('total_area')->first();

            return [(string) ($p['matrikel_id'] ?? '') => [
                'usage' => $primary ? BbrUsageCategory::label($primary['usage'] ?? null) : null,
                // Verified verbatim against a real prod registry-api payload
                // (read-only curl, 2026-07-26): latest_transaction is
                // {"transaction_type","transaction_date","registration_date","price"}
                // — the date field is `transaction_date`, NOT `date`.
                'latest_sale_date' => $p['latest_transaction']['transaction_date'] ?? null,
                'latest_sale_price' => $p['latest_transaction']['price'] ?? null,
                'valuation' => $p['valuation']['estimated_value'] ?? null,
            ]];
        })->all();
    }

    /**
     * Streetview URLs per property: built only when BOTH the portfolio row
     * carries lat/lng AND the google_maps_api_key config is set — otherwise
     * omitted entirely (the builder's array_filter drops null card fields).
     * Keyed onto the SAME enrichment['properties'][matrikel_id] map
     * propertyEnrichmentFromBatch() produced, so a property with no batch
     * entry at all still gets a streetview_url if it has coordinates.
     */
    protected function attachStreetviewUrls(): void
    {
        $apiKey = config('metis.google_maps_api_key');
        if (! $apiKey) {
            return;
        }

        foreach ($this->propertyData['list'] ?? [] as $p) {
            $mid = (string) ($p['matrikel_id'] ?? '');
            $lat = $p['latitude'] ?? null;
            $lng = $p['longitude'] ?? null;

            if ($mid === '' || $lat === null || $lng === null) {
                continue;
            }

            $this->enrichmentData['properties'][$mid] ??= [];
            $this->enrichmentData['properties'][$mid]['streetview_url'] =
                "https://maps.googleapis.com/maps/api/streetview?size=640x400&location={$lat},{$lng}&key={$apiKey}";
        }
    }

    /**
     * Neither protected input survives Livewire hydration across requests, so
     * both are re-checked here: $structureData empty → refetch; $propertyData
     * empty while $propertiesStatus still says 'loaded' → refetch, or the
     * property nodes would silently vanish from the next rebuild. Called at
     * the start of every action that rebuilds (poll, expand, loadProperties,
     * loadEnrichment). Symmetric third check: $enrichmentData empty while
     * $enrichmentStatus still says 'loaded' → re-run loadEnrichment's fetch
     * (every source it reads is cached, so this is cheap), or company/property
     * cards would silently vanish from the next rebuild after a fresh request.
     */
    protected function rehydrateBeforeRebuild(): void
    {
        if ($this->structureData === []) {
            $this->refreshStructureData();
        }
        if ($this->propertiesStatus === 'loaded' && $this->propertyData['list'] === []) {
            $this->refreshPropertyData();
        }
        if ($this->enrichmentStatus === 'loaded' && $this->enrichmentData['companies'] === []) {
            $this->refreshEnrichmentData();
        }
    }

    /**
     * Re-fetches enrichment via the same pooled/cached sources loadEnrichment()
     * used — cheap (24h company-info cache, 5min portfolio cache). Does NOT
     * flip enrichmentStatus: it already says 'loaded' from a prior request, and
     * a transient re-fetch error here shouldn't regress the section into an
     * error the user never triggered (mirrors refreshPropertyData's swallow).
     * Unlike loadEnrichment(), a total failure here is swallowed rather than
     * flipping to 'failed' — same reasoning as refreshPropertyData().
     */
    protected function refreshEnrichmentData(): void
    {
        rescue(fn () => $this->fetchEnrichmentData());
    }

    /**
     * Shared fetch body for loadEnrichment()/refreshEnrichmentData(): pools
     * company-info for every cvr in the graph, batches property enrichment
     * for the currently-loaded portfolio, and layers streetview URLs on top.
     * Callers decide what a total failure means (loadEnrichment flips to
     * 'failed'; refreshEnrichmentData swallows it) — this method itself lets
     * exceptions propagate.
     */
    protected function fetchEnrichmentData(): void
    {
        $cvrs = collect($this->graphModel['nodes'] ?? [])->pluck('cvr')->filter()->unique()->values()->all();
        $companies = $cvrs === [] ? [] : app(RegistryApi::class)->fetchCompanyInfosPooled($cvrs);

        $this->enrichmentData['companies'] = collect($companies)
            ->filter()
            ->map(fn ($company) => $this->companyEnrichmentFromInfo($company))
            ->all();

        $matrikelIds = collect($this->propertyData['list'] ?? [])->pluck('matrikel_id')->filter()->map(fn ($m) => (string) $m)->unique()->values()->all();
        $batch = $matrikelIds === [] ? [] : (rescue(fn () => app(RegistryApi::class)->fetchPropertiesBatch($matrikelIds), null) ?? []);
        $this->enrichmentData['properties'] = $this->propertyEnrichmentFromBatch($batch);
        $this->attachStreetviewUrls();
    }

    /**
     * Cachet variant bruges KUN når enrichment ikke kører — mens enrichment
     * kører skal den ucachede fetchCompanyStructure() bruges, ellers fryser
     * datter-væksten i op til 5 minutter (Task 6's cache-kontrakt).
     */
    protected function refreshStructureData(): void
    {
        $api = app(RegistryApi::class);
        $result = $this->enriching
            ? rescue(fn () => $api->fetchCompanyStructure($this->query), [])
            : rescue(fn () => $api->fetchCompanyStructureCached($this->query), []);

        $this->structureData = $result;
        if (empty($this->owners)) {
            $this->owners = $result['owners'] ?? [];
        }
        $this->companyName = $this->companyName ?? ($result['name'] ?? null);
    }

    /**
     * Re-fetches via the same cached fetchCompanyPropertyPortfolio() the
     * first load used (Task 6: cached 5 min) — cheap, not a re-scrape. A
     * failure here is swallowed rather than flipping to 'failed': the
     * portfolio already loaded once this session, so a transient re-fetch
     * error shouldn't regress the section into an error the user never triggered.
     */
    protected function refreshPropertyData(): void
    {
        // limit: 500 — same reasoning as loadProperties() above; also keeps
        // the cache key identical so this re-fetch stays a cache hit.
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($this->query, limit: 500), null);
        $list = $result['portfolio']['properties'] ?? [];

        if ($list === []) {
            return;
        }

        $this->propertyData = ['list' => $list, 'usage' => $this->usageMapFor($list)];
    }

    /**
     * `now` is intentionally CarbonImmutable::now() — non-determinism lives
     * HERE, in the component layer, never inside OwnershipGraphBuilder
     * itself (which stays pure/deterministic: same input → same output, per
     * its class docblock). Every rebuild() call gets a fresh "now", exactly
     * like a real page render would.
     */
    protected function rebuild(): void
    {
        $this->graphModel = app(OwnershipGraphBuilder::class)->build(
            query: $this->query,
            companyName: $this->companyName,
            structure: $this->structureData,
            properties: $this->propertyData,
            enrichment: $this->enrichmentData,
            expandedNodeIds: $this->expandedNodeIds,
            caps: ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 120],
            now: \Carbon\CarbonImmutable::now(),
        );
    }

    public function render()
    {
        return view('metis::livewire.sections.company-structure');
    }
}
