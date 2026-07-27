<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Livewire\Concerns\ResolvesGraphEnrichment;
use TheFountainhead\Metis\Services\OwnershipGraphBuilder;
use TheFountainhead\Metis\Services\RegistryApi;

class CompanyStructure extends MetisSection
{
    /**
     * Task 8: the enrichment-resolution half of this component now lives in a
     * trait shared with PersonStructure — companyEnrichmentFromInfo() (with
     * its source-dependent t.DKK/kroner rule), propertyEnrichmentFromBatch(),
     * attachStreetviewUrls() and fetchEnrichmentData(). The GATING
     * (loadEnrichment's three gates, retryEnrichment, the rehydration guard)
     * stays here, because it is written against this component's own status
     * model, which is not the person component's.
     */
    use ResolvesGraphEnrichment;

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

            // F1 fix (Opus review): this call was DOCUMENTED on loadEnrichment()'s
            // own docblock ("the existing enrichment poll-completion path calls
            // this at the end") but never actually wired up — a company whose
            // subsidiary-tree enrichment was still 'running'/'pending' at mount
            // would sit at enrichmentStatus='pending' forever, since nothing else
            // ever calls loadEnrichment() again once $enriching flips false here.
            // loadEnrichment() itself gates on propertiesStatus (F2) and on
            // enrichmentStatus==='loaded' (F3), so calling it unconditionally
            // here is safe — it degrades to a no-op when either gate isn't ready.
            $this->loadEnrichment();
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

    /**
     * F2 fix (Opus review): calls loadEnrichment() exactly once, at the end,
     * for every SETTLED propertiesStatus outcome ('loaded'/'empty'/'failed')
     * — 'building' is the one non-terminal outcome and does NOT call it, since
     * the Blade's own re-poll will call loadProperties() again shortly.
     * loadEnrichment() has its own gates (propertiesStatus must be settled;
     * enrichmentStatus must not already be 'loaded') — it decides whether
     * this call actually does anything, so a single unconditional call here
     * is always safe, never a duplicate pool/batch fetch.
     */
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
        } else {
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
            } elseif ($list === []) {
                $this->propertiesStatus = 'empty';
            } else {
                $this->propertyData = ['list' => $list, 'usage' => $this->usageMapFor($list)];
                $this->propertiesStatus = 'loaded';
                $this->rebuild();
            }
        }

        if ($this->propertiesStatus !== 'building') {
            $this->loadEnrichment();
        }
    }

    /**
     * F2+F3 interaction fix (Opus re-review, 2026-07-26): a prior failed (or
     * empty) properties outcome can ALREADY have driven enrichmentStatus to
     * 'loaded' — loadProperties()'s trailing loadEnrichment() call runs once
     * propertiesStatus settles to 'failed', and 'failed' counts as settled
     * (F2), so enrichment proceeds against an empty propertyData and reaches
     * 'loaded' with empty property cards. If the user then retries and the
     * portfolio succeeds this time, loadProperties()'s OWN trailing
     * loadEnrichment() call would hit F3's already-loaded gate and no-op
     * PERMANENTLY — enrichmentData['properties'] never gets the real batch
     * data, and F4's rehydration guard never fires either (it only runs
     * inside loadEnrichment(), which F3 blocks before rehydration is even
     * reached). Resetting enrichmentStatus to 'pending' here — same
     * discipline as the propertiesAttempts reset just above — means the
     * trailing loadEnrichment() after a successful retry runs fresh, exactly
     * as if enrichment had never happened for this component.
     */
    public function retryProperties(): void
    {
        $this->propertiesStatus = 'pending';
        $this->propertiesAttempts = 0;
        $this->enrichmentStatus = 'pending';
        $this->loadProperties();
    }

    /**
     * Fase 2a.2: fetches per-company financials/contact/etc (pooled) and the
     * full per-property batch map (usage + latest sale + valuation), then
     * rebuilds so OwnershipGraphBuilder can attach cards/signals to nodes.
     *
     * THREE independent gates, all must be satisfied or this is a no-op that
     * leaves enrichmentStatus untouched:
     *
     * 1. `$this->enriching` — the subsidiary tree is still growing, so
     *    enriching now would be wasted work on cvr's the next poll may
     *    prune/replace. pollForUpdates() calls this again once it flips
     *    $enriching false (F1 fix).
     * 2. `$this->propertiesStatus` not yet settled (`'pending'`/`'building'`)
     *    — the property list (and therefore the matrikel-ids to batch-enrich)
     *    isn't final yet. 'loaded', 'empty', AND 'failed' all count as
     *    "settled": a portfolio that will never load is not a reason to
     *    withhold company-level enrichment forever (F2 fix — previously
     *    this method had NO properties-gate at all, so calling it before the
     *    portfolio landed produced a permanently-empty properties map that
     *    nothing ever repaired, because loadProperties() never touched
     *    enrichmentData and the rehydration guard only fires when
     *    enrichmentStatus is ALREADY 'loaded').
     * 3. `$this->enrichmentStatus === 'loaded'` already — repeat calls (the
     *    Blade's x-init trigger can re-fire across morphs) must not re-pool
     *    every company and re-batch every property each time (F3 fix). Use
     *    retryEnrichment() to force a genuine re-fetch after a 'failed' state.
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
        if (! in_array($this->propertiesStatus, ['loaded', 'empty', 'failed'], true)) {
            return;
        }
        if ($this->enrichmentStatus === 'loaded') {
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
     * F5: explicit retry after enrichmentStatus === 'failed' (mirrors
     * retryProperties() above) — resets to 'pending' so loadEnrichment()'s
     * own idempotency gate (F3) doesn't immediately no-op the retry.
     */
    public function retryEnrichment(): void
    {
        $this->enrichmentStatus = 'pending';
        $this->loadEnrichment();
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

    /** Trait hook: this component holds one flat portfolio list for the searched company. */
    protected function enrichmentPropertyRows(): array
    {
        return array_values($this->propertyData['list'] ?? []);
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
     * F4 fix (Opus review): guards BOTH enrichmentData['companies'] AND
     * enrichmentData['properties'] — guarding only 'companies' let the
     * properties half of the map get lost asymmetrically (e.g. a fresh
     * request where company cards happened to be non-empty from some other
     * path but the property batch map wasn't), silently dropping property
     * cards from the rebuilt graph without ever triggering a refetch.
     */
    protected function rehydrateBeforeRebuild(): void
    {
        if ($this->structureData === []) {
            $this->refreshStructureData();
        }
        if ($this->propertiesStatus === 'loaded' && $this->propertyData['list'] === []) {
            $this->refreshPropertyData();
        }
        if ($this->enrichmentStatus === 'loaded'
            && ($this->enrichmentData['companies'] === [] || $this->enrichmentData['properties'] === [])) {
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
     *
     * 🚨 REBUILDS FIRST. Task 8 made the enrichment scope GRAPH-derived (only
     * the cvrs and matrikel-ids actually drawn are fetched — see the trait's
     * enrichmentMatrikelIds()), and on a recovery pass the graph in
     * $graphModel is the one that arrived over the wire, built before the
     * structure/property data above was recovered. Resolving against it asked
     * about whichever nodes that stale model happened to hold — which on a
     * fresh request whose graphModel had not been hydrated at all was NONE,
     * so the property half of the map came back empty and the cards silently
     * vanished (caught by the F4 regression test, which is exactly the
     * failure it was written for). Rebuilding on the recovered inputs first
     * makes the id set describe the graph enrichment is about to be applied
     * to; loadEnrichment()/the caller rebuild again afterwards to attach it.
     */
    protected function refreshEnrichmentData(): void
    {
        $this->rebuild();

        rescue(fn () => $this->fetchEnrichmentData());
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
