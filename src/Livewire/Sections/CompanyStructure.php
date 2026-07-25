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

        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($this->query), null);
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
     * matrikel_id => primær anvendelses-label via properties/batch + BbrUsageCategory.
     * En NULL fra fetchPropertiesBatch (ét chunk fejlede, alt-eller-intet) er
     * en batch-fejl — IKKE en portfolio-fejl: ejendommene vises stadig, blot
     * uden anvendelses-række. propertiesStatus forbliver 'loaded'.
     */
    protected function usageMapFor(array $properties): array
    {
        $ids = collect($properties)->pluck('matrikel_id')->filter()->map(fn ($m) => (string) $m)->unique()->values()->all();
        $batch = rescue(fn () => app(RegistryApi::class)->fetchPropertiesBatch($ids), null) ?? [];

        return collect($batch)->mapWithKeys(function ($p) {
            $buildings = collect($p['bbr']['buildings'] ?? []);
            // Primær bygning: største areal blandt ikke-småbygninger (9xx-koder =
            // garager/udhuse); fallback = største uanset kode.
            $primary = $buildings->filter(fn ($b) => (int) ($b['usage'] ?? 0) < 900)->sortByDesc('total_area')->first()
                ?? $buildings->sortByDesc('total_area')->first();

            return [(string) ($p['matrikel_id'] ?? '') => $primary ? BbrUsageCategory::label($primary['usage'] ?? null) : null];
        })->all();
    }

    /**
     * Neither protected input survives Livewire hydration across requests, so
     * both are re-checked here: $structureData empty → refetch; $propertyData
     * empty while $propertiesStatus still says 'loaded' → refetch, or the
     * property nodes would silently vanish from the next rebuild. Called at
     * the start of every action that rebuilds (poll, expand, loadProperties).
     */
    protected function rehydrateBeforeRebuild(): void
    {
        if ($this->structureData === []) {
            $this->refreshStructureData();
        }
        if ($this->propertiesStatus === 'loaded' && $this->propertyData['list'] === []) {
            $this->refreshPropertyData();
        }
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
        $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($this->query), null);
        $list = $result['portfolio']['properties'] ?? [];

        if ($list === []) {
            return;
        }

        $this->propertyData = ['list' => $list, 'usage' => $this->usageMapFor($list)];
    }

    protected function rebuild(): void
    {
        $this->graphModel = app(OwnershipGraphBuilder::class)->build(
            query: $this->query,
            companyName: $this->companyName,
            structure: $this->structureData,
            properties: $this->propertyData,
            enrichment: [],
            expandedNodeIds: $this->expandedNodeIds,
            caps: ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 120],
        );
    }

    public function render()
    {
        return view('metis::livewire.sections.company-structure');
    }
}
