<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\OwnershipGraphBuilder;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Fase 2b: the CPR person page's ownership graph — the person as root,
 * ownership chains down to subsidiaries/properties, plus a ROLE layer
 * (board/director seats without ownership) as dashed edges. Replaces the old
 * PersonNetwork org-chart + separate board table; the roles are now a layer
 * in the graph.
 *
 * Fase 1 (skeleton) runs at mount; fases 2 (structures) and 3 (properties)
 * are driven by tick(), the single wire:poll entry point. Fase 4 (enrichment)
 * lands in Task 8 — its status property is DEFINED here, at its 'pending'
 * start value, so the Blade's phase-gating was written once and never
 * revisited.
 *
 * 🚨 CPR must NEVER appear in the graph payload — node ids, edges, labels or
 * DOM attributes. $this->query IS the CPR (the URL already carries it, as it
 * does today), so the person root uses the id 'person:root' and a name that
 * is never derived from the CPR digits. See personLabel().
 */
class PersonStructure extends MetisSection
{
    /** 2a's caps + fase 2b's two first-level caps (spec §First-level caps). */
    protected const CAPS = [
        'subsidiary_depth' => 2,
        'properties_per_company' => 6,
        'total_nodes' => 120,
        'person_roots' => 20,
        'person_roles' => 15,
    ];

    /**
     * cvrs fetched per tick, per phase. Structures go out as ONE pooled call
     * (RegistryApi's own concurrency 3), so a tick costs the slowest of three
     * rather than their sum; properties are sequential per-cvr calls, capped
     * at the same 3 so a tick stays inside a poll interval either way.
     */
    protected const CVRS_PER_TICK = 3;

    /**
     * Shared 'building'-attempt budget for the WHOLE properties phase — not
     * per cvr. A portfolio the backend never finishes assembling answers
     * 'building' forever; without a ceiling the poll spins for the life of the
     * page. Shared rather than per-cvr because the cost being bounded is the
     * user's total wait, which N companies each burning their own budget would
     * multiply by N.
     */
    protected const MAX_PROPERTIES_ATTEMPTS = 24;

    /**
     * The flat {nodes, edges} graph model — public so the Alpine graph can
     * `$wire.$watch('graphModel', …)` it and re-lay-out in place without a
     * wire:ignore re-mount (the user's zoom/pan is Alpine state that must
     * survive a phase poll). Every code path REBUILDS this through
     * OwnershipGraphBuilder::buildForPerson(); nothing ever appends to it.
     */
    public array $graphModel = ['nodes' => [], 'edges' => []];

    /** @var 'pending'|'loading'|'loaded'|'empty'|'failed' */
    public string $skeletonStatus = 'pending';

    /** @var 'pending'|'loading'|'loaded'|'failed' — fase 2 aggregate (Task 7). */
    public string $structuresStatus = 'pending';

    /** cvr => 'pending'|'loading'|'loaded'|'failed' (Task 7). */
    public array $structureByCompany = [];

    /** @var 'pending'|'building'|'loaded'|'empty'|'failed' — fase 3 aggregate (Task 7). */
    public string $propertiesStatus = 'pending';

    /** cvr => 'pending'|'building'|'loaded'|'empty'|'failed' (Task 7). */
    public array $propertiesByCompany = [];

    /** Consumed units of the SHARED MAX_PROPERTIES_ATTEMPTS budget. */
    public int $propertiesAttempts = 0;

    /** @var 'pending'|'loaded'|'failed' — fase 4 (Task 8). */
    public string $enrichmentStatus = 'pending';

    /**
     * Active filter chips. Both preselected; toggleLayer() enforces the
     * never-empty rule server-side (the Blade only disables the button).
     *
     * @var list<'ownership'|'roles'>
     */
    public array $layers = ['ownership', 'roles'];

    /** 'sub:person:root' / 'roles:person:root' / 'sub:<cvr>' / 'props:<cvr>'. */
    public array $expandedNodeIds = [];

    /**
     * Chip badge counts. Deliberately PRE-cap: they answer "how many relations
     * does this person have", not "how many are currently drawn". A post-cap
     * count would shrink to the cap (20/15) and make the hidden remainder
     * invisible in the very control meant to advertise it — the person-root
     * expand button is what reveals the difference.
     */
    public int $ownershipCount = 0;

    public int $roleCount = 0;

    /**
     * True when a refetch after hydration failed and the graph on screen is
     * therefore the last-good one rather than a fresh build. Drives a Blade
     * note; also the flag callers check to know a rebuild must be SKIPPED.
     */
    public bool $staleData = false;

    /**
     * Builder input, held as PROTECTED state: never part of the Livewire wire
     * payload, so the raw companies rows (which carry the person's role
     * history) are never round-tripped to the browser. Protected properties
     * do NOT survive Livewire hydration — each request builds a fresh
     * instance from public state alone — so every action that rebuilds calls
     * rehydrateBeforeRebuild() first (2a's CompanyStructure discipline).
     */
    protected array $companiesData = [];

    protected array $crossOwnershipData = [];

    /** cvr => structure payload (Task 7). */
    protected array $structureData = [];

    /**
     * cvr => PLAIN LIST of portfolio rows (Task 7). NOT build()'s
     * ['list' => …, 'usage' => …] wrapper — buildForPerson() THROWS on that
     * shape rather than silently rendering a graph with zero properties.
     */
    protected array $propertyData = [];

    protected array $enrichmentData = ['companies' => [], 'properties' => []];

    protected function sectionTitle(): string
    {
        return __('Selskabsstruktur');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $this->loadSkeleton();
    }

    /**
     * Fase 1. null ≠ tom: a FAILED call (null, or post()'s ['error' => …]
     * error-array) means 'failed' + a retry affordance; only a SUCCESSFUL
     * response with no companies is 'empty'. Conflating the two would tell a
     * person with a dozen companies that they have none, which is worse than
     * showing an error.
     */
    protected function loadSkeleton(): void
    {
        $this->skeletonStatus = 'loading';

        // Cleared HERE rather than on the success path only: 'failed' means
        // there is no graph at all, so "showing the last known view" alongside
        // it is a contradiction. Clearing up front makes the invariant hold in
        // STATE for every outcome, instead of resting on Blade nesting.
        $this->staleData = false;

        $result = $this->fetchCompanies();

        if ($result === null) {
            $this->skeletonStatus = 'failed';

            return;
        }

        $this->companiesData = $result;
        [$ownership, $roles] = $this->classify();

        if ($ownership === [] && $roles === []) {
            $this->skeletonStatus = 'empty';

            return;
        }

        // One cross-ownership call, only when there are ≥2 ownership cvrs to
        // relate. A FAILURE here fails the WHOLE skeleton: without the
        // parent/child dedup a subsidiary would be drawn as a second root, so
        // the graph would be WRONG rather than merely incomplete.
        if (! $this->fetchCrossOwnership($ownership)) {
            $this->skeletonStatus = 'failed';

            return;
        }

        $this->skeletonStatus = 'loaded';
        $this->rebuild();
    }

    /**
     * Re-runs fase 1 from scratch after a 'failed' skeleton. Resets the
     * downstream phases too: a fresh companies list can name a completely
     * different set of cvrs, so any per-cvr status carried over from the
     * failed attempt would describe companies that are no longer in the graph.
     */
    public function retrySkeleton(): void
    {
        $this->resetDownstreamPhases();
        $this->loadSkeleton();
    }

    /**
     * Returns phases 2-4 to their untouched start state. Tasks 7-8 add their
     * own retry paths on top of the same method, so the "what belongs to a
     * downstream phase" list lives in exactly one place.
     */
    protected function resetDownstreamPhases(): void
    {
        $this->structuresStatus = 'pending';
        $this->structureByCompany = [];
        $this->propertiesStatus = 'pending';
        $this->propertiesByCompany = [];
        $this->propertiesAttempts = 0;
        $this->enrichmentStatus = 'pending';
        $this->structureData = [];
        $this->propertyData = [];
        $this->enrichmentData = ['companies' => [], 'properties' => []];
    }

    /**
     * Chip toggle. NEVER-EMPTY RULE, enforced here rather than in the Blade:
     * a toggle whose result would leave nothing but the person node is
     * rejected outright — state is left completely untouched, no rebuild, no
     * refit. Switching a chip for an EMPTY layer off is always allowed (it
     * removes nothing), which is what makes a roles-only person able to hide
     * the ownership chip but not the roles chip.
     */
    public function toggleLayer(string $layer): void
    {
        if (! in_array($layer, ['ownership', 'roles'], true)) {
            return;
        }

        // Abandon the whole toggle if the inputs could not be recovered — the
        // layers stay put too, so the chips never describe a graph that was
        // never rebuilt.
        if (! $this->rehydrateBeforeRebuild()) {
            return;
        }

        $next = in_array($layer, $this->layers, true)
            ? array_values(array_diff($this->layers, [$layer]))
            : [...$this->layers, $layer];

        if (count($this->buildGraph($next)['nodes']) <= 1) {
            return;
        }

        $this->layers = $next;
        $this->rebuild();
        $this->dispatch('graph-refit');
    }

    /**
     * nodeId = 'sub:person:root' / 'roles:person:root' (lift a first-level
     * cap) or 'sub:<cvr>' / 'props:<cvr>' (2a's per-node caps).
     */
    public function expandNode(string $nodeId): void
    {
        if (! str_starts_with($nodeId, 'sub:') && ! str_starts_with($nodeId, 'roles:') && ! str_starts_with($nodeId, 'props:')) {
            return;
        }

        // Same degradation as toggleLayer(): never rebuild on partial input.
        if (! $this->rehydrateBeforeRebuild()) {
            return;
        }

        // The person root shows ONE expand button whose count folds the hidden
        // ownership roots AND the hidden role companies into a single number
        // (the builder's expand.relations). Lifting only one of the two caps
        // would therefore reveal fewer companies than the button advertised,
        // and leave no affordance for the rest — so one click lifts BOTH.
        $ids = $nodeId === 'sub:person:root' || $nodeId === 'roles:person:root'
            ? ['sub:person:root', 'roles:person:root']
            : [$nodeId];

        foreach ($ids as $id) {
            if (! in_array($id, $this->expandedNodeIds, true)) {
                $this->expandedNodeIds[] = $id;
            }
        }

        $this->rebuild();
    }

    /**
     * PersonNetwork's classification rules, ported verbatim:
     *   - only `is_active` companies (historical roles are out of scope);
     *   - `has_direct_ownership` splits ownership from role-only. NB
     *     beneficial_owner is INDIRECT ownership through a company chain and
     *     is deliberately NOT direct ownership — it surfaces as a subsidiary
     *     edge in fase 2, not as a person edge;
     *   - ownership share = the first CURRENT role that actually carries one
     *     (a person can hold several roles in one company, only one of which
     *     records a share);
     *   - role_label = title ?? role ?? null (the builder supplies its own
     *     'rolle' fallback for the edge label, so null is a valid value here
     *     and must not be pre-flattened to a string).
     *
     * @return array{0: list<array>, 1: list<array>}
     */
    protected function classify(): array
    {
        $active = collect($this->companiesData)
            ->filter(fn ($c) => ($c['is_active'] ?? false) && ($c['cvr'] ?? null));

        $ownership = $active->filter(fn ($c) => $c['has_direct_ownership'] ?? false)
            ->map(fn ($c) => [
                'cvr' => $c['cvr'],
                'name' => $c['name'] ?? null,
                'company_type' => $c['company_type'] ?? null,
                'ownership_share' => $this->currentRoles($c)
                    ->pluck('ownership_share')->filter()->first(),
            ])->values()->all();

        $roles = $active->reject(fn ($c) => $c['has_direct_ownership'] ?? false)
            ->map(fn ($c) => [
                'cvr' => $c['cvr'],
                'name' => $c['name'] ?? null,
                'company_type' => $c['company_type'] ?? null,
                'role_label' => $this->currentRoles($c)->pluck('title')->filter()->first()
                    ?? $this->currentRoles($c)->pluck('role')->filter()->first(),
            ])->values()->all();

        return [$ownership, $roles];
    }

    protected function currentRoles(array $company): \Illuminate\Support\Collection
    {
        return collect($company['roles'] ?? [])->where('is_current', true);
    }

    /**
     * Fills $crossOwnershipData. Returns false ONLY on a real failure — a
     * skipped call (<2 ownership cvrs) and a successful empty response are
     * both true, because neither leaves the graph wrong.
     */
    protected function fetchCrossOwnership(array $ownership): bool
    {
        $cvrs = collect($ownership)->pluck('cvr')->filter()->unique()->values()->all();

        if (count($cvrs) < 2) {
            $this->crossOwnershipData = [];

            return true;
        }

        $result = $this->attempt(fn () => app(RegistryApi::class)->fetchCrossOwnership($cvrs));

        if ($result === null) {
            return false;
        }

        $this->crossOwnershipData = $result['relationships'] ?? [];

        return true;
    }

    /** The companies[] list, or null if the call failed in ANY of its ways. */
    protected function fetchCompanies(): ?array
    {
        $result = $this->attempt(fn () => app(RegistryApi::class)->fetchCompaniesByCprCached($this->query));

        return $result === null ? null : ($result['companies'] ?? []);
    }

    /**
     * Runs a RegistryApi call and normalises its THREE distinct failure modes
     * into a single null. Verified against the live client, not assumed:
     *
     *   1. `['error' => …, 'status' => …]` — post()'s catch-block converts a
     *      RequestException (4xx/5xx) into this array rather than throwing.
     *   2. `TypeError` — post() is declared `: array` but returns
     *      `->json('data')`, which is NULL for a 200 whose body has no 'data'
     *      key. PHP then throws on the return type. Uncaught, that is a
     *      white-screen 500 for a merely malformed upstream response.
     *   3. `ConnectionException` — DNS/refused/timeout never reaches post()'s
     *      RequestException catch at all and propagates out of the client.
     *
     * Catching Throwable covers 2 and 3 together (and anything else the client
     * grows later); the isset() check covers 1. A null return always means
     * "this call did not produce trustworthy data" — never "the answer was
     * empty", which is the distinction the whole null≠tom rule rests on.
     */
    protected function attempt(callable $call): ?array
    {
        try {
            $result = $call();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $result === null || isset($result['error']) ? null : $result;
    }

    /**
     * The first-level cvrs the phases must fetch: every ownership root and
     * role company inside the person_roots / person_roles caps (or revealed by
     * an expand). Read off a BUILT graph rather than recomputed, so the
     * cap/demotion logic lives in exactly one place: the builder.
     *
     * 🚨 Built against ALL layers, deliberately NOT $this->layers. Two rules
     * that look alike but are not:
     *
     *   - TRUNCATION (a company past the first-level cap) is about work no one
     *     asked for: the company is not drawn and cannot be revealed without
     *     an explicit expand, so fetching its subsidiaries is pure waste. It
     *     stays out of the queues, exactly as before.
     *   - A CHIP is about what is drawn right now. Spec §Chips: "Hentning
     *     fortsætter uanset chip-tilstand — chips filtrerer kun bygningen",
     *     so that re-enabling a chip "viser allerede-hentet data øjeblikkeligt".
     *
     * Reading the cvrs off $this->graphModel conflated the two: probed before
     * this changed, switching the roles chip off dropped the role company out
     * of structureByCompany entirely, so its subsidiaries were never fetched —
     * and switching the chip back on would have shown a company that silently
     * had no subtree, with nothing left in any queue to ever repair it.
     */
    protected function visibleFirstLevelCvrs(): array
    {
        return collect($this->buildGraph(['ownership', 'roles'])['nodes'])
            ->filter(fn ($n) => ($n['depth'] ?? null) === 1 && ($n['cvr'] ?? null))
            ->pluck('cvr')->unique()->values()->all();
    }

    /**
     * Fase 2's work queue: cvr => status for every first-level company inside
     * the caps (ownership roots AND role companies — both must be able to
     * reveal subsidiaries). tick() consumes this, taking the 'pending' entries
     * CVRS_PER_TICK at a time.
     *
     * Recomputed on every visibility change, because visibility is not fixed
     * at mount: a person-root expand reveals companies that were behind the
     * first-level cap, and those must join the queue or their subsidiaries are
     * never fetched. Truncated companies are deliberately absent until
     * expanded; chip state is deliberately IRRELEVANT (see
     * visibleFirstLevelCvrs()).
     *
     * Statuses already SETTLED are carried over verbatim — a recompute must
     * never reset 'loaded'/'failed' back to 'pending', or every toggle would
     * re-fetch structures the component already holds.
     */
    protected function refreshStructureQueue(): void
    {
        $this->structureByCompany = collect($this->visibleFirstLevelCvrs())
            ->mapWithKeys(fn ($cvr) => [$cvr => $this->structureByCompany[$cvr] ?? 'pending'])
            ->all();

        // Reopen the aggregate whenever ANY entry is pending — not just when it
        // is still at its initial 'pending'. An expand can strand new work
        // behind an already-settled phase: Task 7 finishes, the aggregate goes
        // 'loaded', then the user lifts the first-level cap and the revealed
        // companies enter the queue as 'pending'. Gated on the initial state
        // alone, the aggregate would stay 'loaded' and a poll watching for
        // 'loading' would never fetch them — permanently, with no signal.
        if (in_array('pending', $this->structureByCompany, true) && $this->structuresStatus !== 'loading') {
            $this->structuresStatus = 'loading';
        }
    }

    /**
     * Fase 3's work queue, the exact mirror of refreshStructureQueue(): same
     * cvrs, same carry-over-settled-statuses discipline, same reopen rule.
     *
     * It only fills once fase 2 has settled (structuresSettled()), so an
     * expand mid-fase-2 does not start fetching portfolios for a company
     * whose subsidiaries are still arriving — but once fase 3 IS running, an
     * expand's newly revealed companies join it here, through rebuild(), the
     * same single path every queue addition takes.
     *
     * One portfolio call per first-level company covers its whole subtree:
     * the rows carry owner_cvr and the builder hangs each on whichever node
     * matches, root or subsidiary. Never one call per node.
     */
    protected function refreshPropertyQueue(): void
    {
        if (! $this->structuresSettled()) {
            return;
        }

        $this->propertiesByCompany = collect($this->visibleFirstLevelCvrs())
            ->mapWithKeys(fn ($cvr) => [$cvr => $this->propertiesByCompany[$cvr] ?? 'pending'])
            ->all();

        // Same stranded-work reasoning as fase 2's aggregate, with one extra
        // case: 'failed' here can mean the shared budget ran out, and that is
        // a decision the user reverses with retryProperties() — not something
        // a rebuild may quietly undo. So a failed aggregate stays failed.
        if (in_array('pending', $this->propertiesByCompany, true)
            && ! in_array($this->propertiesStatus, ['building', 'failed'], true)) {
            $this->propertiesStatus = 'building';
        }
    }

    /**
     * Fase 2 is done when nothing is left to fetch. 'failed' cvrs count as
     * settled: a company whose structure could not be fetched can still own
     * properties, and blocking fase 3 on it would let one bad cvr withhold the
     * whole property layer (spec regel 4).
     */
    protected function structuresSettled(): bool
    {
        return ! in_array('pending', $this->structureByCompany, true)
            && ! in_array('loading', $this->structureByCompany, true);
    }

    /**
     * The single wire:poll entry point for BOTH progressive phases. Fase 2
     * first and exclusively: structures change which companies exist in the
     * graph, so starting fase 3 in the same tick would key portfolios off a
     * queue that is about to change.
     *
     * 🚨 Every dispatch decision below is gated on the per-cvr QUEUE, never on
     * the aggregate status. The aggregate is DERIVED from the queue (see
     * settleStructures()), so gating on it would be gating on a shadow of the
     * real state — and a queue entry stranded behind a stale aggregate would
     * never be fetched again, silently and permanently.
     */
    public function tick(): void
    {
        if ($this->skeletonStatus !== 'loaded') {
            return;
        }

        if (in_array('pending', $this->structureByCompany, true)) {
            $this->tickStructures();

            return;
        }

        if (in_array('pending', $this->propertiesByCompany, true)) {
            $this->tickProperties();
        }
    }

    /**
     * One fase-2 batch: the first CVRS_PER_TICK pending cvrs, in queue order
     * (which is companies order — refreshStructureQueue preserves the graph's
     * node order, which is the caller's companies order), fetched as ONE
     * pooled call.
     *
     * A null in the pooled result means that cvr failed; it settles as
     * 'failed' and the phase carries on. Per-cvr independence is the whole
     * point of the map: one unreachable company must not withhold every other
     * company's subsidiaries.
     */
    protected function tickStructures(): void
    {
        // A tick is a fresh request, so the protected builder inputs are gone.
        // Abandoning here (rather than rebuilding on partial input) keeps the
        // last-good graph, exactly as toggleLayer/expandNode do — and leaves
        // the queue untouched, so the next tick retries the same batch.
        if (! $this->rehydrateBeforeRebuild()) {
            return;
        }

        $batch = collect($this->structureByCompany)
            ->filter(fn ($status) => $status === 'pending')
            ->take(self::CVRS_PER_TICK)
            ->keys()->map(fn ($cvr) => (string) $cvr)->all();

        if ($batch === []) {
            return;
        }

        $results = $this->attempt(fn () => app(RegistryApi::class)->fetchCompanyStructuresPooled($batch)) ?? [];

        foreach ($batch as $cvr) {
            $structure = $results[$cvr] ?? null;

            if ($structure === null) {
                $this->structureByCompany[$cvr] = 'failed';

                continue;
            }

            $this->structureData[$cvr] = $structure;
            $this->structureByCompany[$cvr] = 'loaded';
        }

        // Rebuild unconditionally: a settled batch changes the graph whenever
        // ANY cvr loaded (new subsidiary nodes), and even an all-failed batch
        // must rebuild — that is the call that refreshes the queues and lets
        // fase 3 seed itself off the now-settled fase 2.
        $this->settleStructures();
        $this->rebuild();
    }

    /**
     * Derives the fase-2 aggregate from the queue. Only ever called once the
     * queue itself has been written, so the aggregate cannot drift from it:
     * 'failed' iff at least one cvr failed, otherwise 'loaded'.
     */
    protected function settleStructures(): void
    {
        if (! $this->structuresSettled()) {
            $this->structuresStatus = 'loading';

            return;
        }

        $this->structuresStatus = in_array('failed', $this->structureByCompany, true) ? 'failed' : 'loaded';
    }

    /**
     * One fase-3 batch: up to CVRS_PER_TICK pending cvrs, each a separate
     * fetchCompanyPropertyPortfolio (limit 500 — same value, and therefore the
     * same cache key, as CompanyStructure/CompanyOverview use, so a large
     * portfolio reuses their warm cache instead of paging in the API's
     * default 25 and making every cap count a lie).
     *
     * Per-cvr outcomes are classified in fetchPortfolioFor().
     *
     * 🚨 The budget is enforced HERE, as a hard gate on dispatching at all —
     * not merely as an input to settleProperties(). Deriving the status alone
     * bounded the STATUS but not the WORK: tick() gates on the queue (as it
     * must), the queue still held 'pending' cvrs, so every further tick kept
     * fetching. Probed at 30 ticks against a portfolio answering 'building'
     * forever: propertiesAttempts reached 30 against a ceiling of 24.
     */
    protected function tickProperties(): void
    {
        if ($this->propertiesAttempts >= self::MAX_PROPERTIES_ATTEMPTS) {
            $this->settleProperties();

            return;
        }

        if (! $this->rehydrateBeforeRebuild()) {
            return;
        }

        $batch = collect($this->propertiesByCompany)
            ->filter(fn ($status) => $status === 'pending')
            ->take(self::CVRS_PER_TICK)
            ->keys()->map(fn ($cvr) => (string) $cvr)->all();

        if ($batch === []) {
            return;
        }

        $wrote = false;

        foreach ($batch as $cvr) {
            $wrote = $this->fetchPortfolioFor($cvr) || $wrote;
        }

        $this->settleProperties();

        // Rebuild only when rows actually landed. A tick that merely burned
        // budget on 'building' responses changed nothing the builder reads, so
        // rebuilding would be pure churn — and churn here is not free: it
        // re-derives both queues and re-serialises the whole graph model to
        // the browser on every one of up to 24 attempts.
        if ($wrote) {
            $this->rebuild();
        }
    }

    /**
     * Fetches ONE cvr's portfolio and writes its per-cvr outcome. The single
     * place the four outcomes are classified — both the poll tick and the
     * recovery pass go through here, so they cannot drift apart (and in
     * particular cannot disagree about which outcomes consume budget):
     *
     *   hard failure   → 'failed'; the other cvrs carry on.
     *   still building → stays 'pending' AND burns one unit of the shared
     *                    budget. A portfolio that answers 'building' forever
     *                    would otherwise spin without limit.
     *   genuinely empty→ 'empty'. A settled answer, not missing data.
     *   rows           → 'loaded', stored as a PLAIN LIST (buildForPerson()
     *                    throws on build()'s ['list','usage'] wrapper rather
     *                    than silently rendering zero properties).
     *
     * limit 500 matches CompanyStructure/CompanyOverview, so the cache key is
     * identical and a large portfolio reuses their warm cache instead of
     * paging in the API's default 25.
     *
     * @return bool whether rows were actually written (i.e. a rebuild is warranted)
     */
    protected function fetchPortfolioFor(string $cvr): bool
    {
        $result = $this->attempt(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($cvr, limit: 500));
        $portfolio = $result['portfolio'] ?? null;

        if ($portfolio === null) {
            $this->propertiesByCompany[$cvr] = 'failed';

            return false;
        }

        $list = $portfolio['properties'] ?? [];
        $count = $portfolio['property_count'] ?? $portfolio['total_count'] ?? 0;

        if ($list === [] && $count > 0) {
            $this->propertiesAttempts++;
            $this->propertiesByCompany[$cvr] = 'pending';

            return false;
        }

        if ($list === []) {
            $this->propertiesByCompany[$cvr] = 'empty';

            return false;
        }

        $this->propertyData[$cvr] = $list;
        $this->propertiesByCompany[$cvr] = 'loaded';

        return true;
    }

    /**
     * Derives the fase-3 aggregate from its queue + the shared budget.
     *
     * The budget check comes FIRST and is terminal: an exhausted budget means
     * the remaining cvrs will never settle on their own, so leaving the
     * aggregate at 'building' would poll forever behind a promise that cannot
     * be kept. 'failed' gives the Blade a retry button instead of a spinner.
     */
    protected function settleProperties(): void
    {
        if ($this->propertiesAttempts >= self::MAX_PROPERTIES_ATTEMPTS
            && in_array('pending', $this->propertiesByCompany, true)) {
            $this->propertiesStatus = 'failed';

            return;
        }

        if (in_array('pending', $this->propertiesByCompany, true)) {
            $this->propertiesStatus = 'building';

            return;
        }

        if (in_array('failed', $this->propertiesByCompany, true)) {
            $this->propertiesStatus = 'failed';

            return;
        }

        // Every cvr settled successfully: 'empty' only when NONE of them had a
        // single property — one company with rows makes the phase 'loaded'.
        $this->propertiesStatus = in_array('loaded', $this->propertiesByCompany, true) ? 'loaded' : 'empty';
    }

    /**
     * Fase-2 retry: puts the failed cvrs back to 'pending' and lets the poll
     * pick them up again. The cvrs that already succeeded are NOT re-fetched.
     *
     * 🚨 RETRY CASCADE. A structures retry is not a fase-2-local event: a cvr
     * that failed has NO subtree in the graph, so whatever fase 3 and fase 4
     * concluded about it was concluded about a company with no subsidiaries.
     * If the retry now succeeds, that subtree arrives un-propertied and
     * un-enriched, permanently, because both later phases have already settled
     * for it. So the retry resets fase 3 for exactly the affected cvrs, and
     * enrichment wholesale (it is graph-wide, not per-cvr, so there is no
     * "affected subset" to reset).
     */
    public function retryStructures(): void
    {
        $retried = collect($this->structureByCompany)
            ->filter(fn ($status) => $status === 'failed')
            ->keys()->map(fn ($cvr) => (string) $cvr)->all();

        if ($retried === []) {
            return;
        }

        foreach ($retried as $cvr) {
            $this->structureByCompany[$cvr] = 'pending';

            // Reset rather than unset: the cvr is still a first-level company
            // and still belongs in fase 3's queue. Unsetting would work too
            // (refreshPropertyQueue would re-seed it), but only via a path
            // gated on fase 2 being settled — leaving a window where the cvr
            // is in NEITHER queue, which is exactly the stranded-work shape
            // this component has already been bitten by twice.
            if (array_key_exists($cvr, $this->propertiesByCompany)) {
                $this->propertiesByCompany[$cvr] = 'pending';
            }

            unset($this->propertyData[$cvr]);
        }

        $this->structuresStatus = 'loading';
        // Back to the phase's own start state: refreshPropertyQueue() is gated
        // on fase 2 being settled, so it will re-seed itself (including the
        // reset cvrs) the moment the retried structures land.
        $this->propertiesStatus = 'pending';
        $this->propertiesAttempts = 0;
        $this->enrichmentStatus = 'pending';
    }

    /**
     * Fase-3 retry: clears the shared budget and puts every unsettled cvr back
     * to 'pending'. 'loaded'/'empty' cvrs keep their result — a retry after a
     * budget exhaustion should resume the phase, not re-fetch portfolios that
     * already arrived.
     */
    public function retryProperties(): void
    {
        $this->propertiesAttempts = 0;
        $this->propertiesByCompany = collect($this->propertiesByCompany)
            ->map(fn ($status) => in_array($status, ['loaded', 'empty'], true) ? $status : 'pending')
            ->all();
        $this->propertiesStatus = 'building';
        $this->enrichmentStatus = 'pending';
    }

    /**
     * Recovers the protected builder inputs, which do not survive hydration.
     * Cache-first, so this is normally a cache hit (5 min) rather than a fresh
     * API round-trip.
     *
     * Returns TRUE only when the caller may safely rebuild. That contract is
     * the whole point: a rebuild on partially-recovered input does not produce
     * a smaller graph, it produces a WRONG one. Two ways it can go wrong, both
     * observed in probes before this guard existed:
     *
     *   - companies lost → the graph collapses to a bare person:root while
     *     skeletonStatus still reads 'loaded', with the chip counts zeroed and
     *     nothing on screen explaining why everything vanished;
     *   - cross-ownership lost → the parent/child demotion silently un-happens,
     *     so a subsidiary is redrawn as a SECOND ROOT and the parent edge
     *     disappears. The person appears to own two independent companies when
     *     in fact one owns the other.
     *
     * On failure the last-good $graphModel is left exactly as it was and
     * $staleData is raised so the Blade can say so. This is deliberately NOT
     * 2a's swallow-and-continue: swallowing is right when the missing piece
     * only makes the graph less complete, and wrong when it makes it false.
     */
    protected function rehydrateBeforeRebuild(): bool
    {
        if ($this->skeletonStatus !== 'loaded') {
            return false;
        }

        if ($this->companiesData === []) {
            $companies = $this->fetchCompanies();

            if ($companies === null) {
                $this->staleData = true;

                return false;
            }

            // Classify off the freshly-fetched rows rather than the stale property.
            $this->companiesData = $companies;
            [$ownership] = $this->classify();

            if (! $this->fetchCrossOwnership($ownership)) {
                // Drop the half-recovered inputs so no later code path in this
                // same request mistakes them for a complete set.
                $this->companiesData = [];
                $this->crossOwnershipData = [];
                $this->staleData = true;

                return false;
            }

            $this->staleData = false;
        }

        $this->recoverPhaseResults();

        return true;
    }

    /**
     * Recovers the fase-2/3 results a previous request already fetched. Both
     * live in protected state, so a poll tick — a fresh instance — starts with
     * empty maps while the PUBLIC per-cvr statuses still say 'loaded'.
     *
     * Without this, every tick rebuilds a graph that has LOST everything the
     * earlier ticks added: the subsidiaries appear, then vanish on the next
     * poll, then reappear, with the queues insisting the work is done.
     *
     * Deliberately partial-tolerant, and deliberately NOT a failure path: this
     * is the "less complete" case, not the "false" one, so a cvr whose refetch
     * fails is DOWNGRADED and handed back to the poll loop rather than
     * abandoning the whole rebuild. Both sources are normally cache hits
     * (structures: warmed by fetchCompanyStructuresPooled itself; properties:
     * the same cached limit-500 call the first fetch used).
     *
     * 🚨 EVERY DOWNGRADE RE-DERIVES THE AGGREGATES. A downgrade puts unsettled
     * work back into a per-cvr map, and the poll gate reads the AGGREGATES —
     * so leaving them alone strands that work behind a closed phase. Probed
     * before this guard existed, with a structure endpoint answering 200 +
     * `data: []` (non-null ⇒ the pooled fetch settles it 'loaded', but the
     * cached read returns [] ⇒ the next tick must downgrade):
     *
     *     tick 1:  queue={11111111: loaded}   agg=loaded   poll=true
     *     tick 2:  queue={11111111: pending}  agg=loaded   poll=FALSE
     *
     * The cvr sat 'pending' forever with the browser no longer polling and no
     * retry button on screen — silent and permanent.
     *
     * Task 8 tightens this further (enrichment-completeness per cvr); the
     * contract here is only that a rebuilt graph never silently loses a layer
     * a settled status claims it has, and never closes a phase over work it
     * has just re-opened.
     */
    protected function recoverPhaseResults(): void
    {
        $downgraded = $this->recoverStructureResults() | $this->recoverPropertyResults();

        if ($downgraded) {
            $this->settleStructures();
            $this->settleProperties();
        }
    }

    /** @return bool whether any cvr was downgraded out of a settled state */
    protected function recoverStructureResults(): bool
    {
        $downgraded = false;

        foreach ($this->structureByCompany as $cvr => $status) {
            if ($status !== 'loaded' || isset($this->structureData[$cvr])) {
                continue;
            }

            $structure = $this->attempt(fn () => app(RegistryApi::class)->fetchCompanyStructureCached((string) $cvr));

            if ($structure === null || $structure === []) {
                $this->structureByCompany[$cvr] = 'pending';
                $downgraded = true;

                continue;
            }

            $this->structureData[(string) $cvr] = $structure;
        }

        return $downgraded;
    }

    /**
     * Property recovery. Routes through fetchPortfolioFor(), so the three
     * unrecoverable outcomes stay APART instead of collapsing into one
     * 'pending' downgrade — a cvr the phase already gave up on must not be
     * quietly re-queued by a recovery pass (only retryProperties() re-opens a
     * failed cvr), and a cvr still building must burn budget like any other
     * attempt rather than re-entering the building path for free.
     *
     * @return bool whether any cvr was downgraded out of a settled state
     */
    protected function recoverPropertyResults(): bool
    {
        $downgraded = false;

        foreach ($this->propertiesByCompany as $cvr => $status) {
            if ($status !== 'loaded' || isset($this->propertyData[$cvr])) {
                continue;
            }

            $downgraded = ! $this->fetchPortfolioFor((string) $cvr) || $downgraded;
        }

        return $downgraded;
    }

    /**
     * The person root's label. The search-by-cpr payload carries NO person
     * name (verified against every consumer in this package: PersonSummary,
     * PersonCompanies, PersonRelations and the old PersonNetwork all read
     * only cvr/name/is_active/has_direct_ownership/roles off it — person_name
     * comes from the SEPARATE roles-by-cvr endpoint). It is read here
     * opportunistically anyway, so the label improves for free the day the
     * API starts sending one, with a neutral fallback until then.
     *
     * The fallback is NEVER $this->query: that is the raw CPR, and the spec
     * forbids CPR in the graph payload — the label is serialised straight
     * into graphModel and shipped to the browser.
     */
    protected function personLabel(): string
    {
        return collect($this->companiesData)
            ->pluck('person_name')->filter()->first()
            ?? __('Personen');
    }

    /**
     * The single choke point for graph state: every visibility change (mount,
     * chip toggle, expand) and every completed poll batch ends here, so BOTH
     * phase queues are refreshed here rather than at each call site — one
     * place that cannot be forgotten. Nothing anywhere else may add a
     * 'pending' entry to either map: this is where an aggregate reopens, so an
     * entry added elsewhere would sit behind a closed phase forever.
     *
     * Counts are PRE-cap by design; see the $ownershipCount docblock.
     */
    protected function rebuild(): void
    {
        $this->graphModel = $this->buildGraph($this->layers);

        [$ownership, $roles] = $this->classify();
        $this->ownershipCount = count($ownership);
        $this->roleCount = count($roles);

        $this->refreshStructureQueue();
        $this->refreshPropertyQueue();
    }

    /**
     * Builds against an ARBITRARY layer set rather than always $this->layers,
     * so toggleLayer() can evaluate the never-empty rule on the graph a
     * candidate toggle WOULD produce — asking the builder instead of
     * re-deriving the visibility rules (caps, cross-ownership demotion,
     * layer-aware dedup) a second time and risking the two disagreeing.
     *
     * `now` is CarbonImmutable::now() here, in the component: the builder
     * stays pure/deterministic (same input → same output) per its docblock.
     */
    protected function buildGraph(array $layers): array
    {
        [$ownership, $roles] = $this->classify();

        return app(OwnershipGraphBuilder::class)->buildForPerson(
            personName: $this->personLabel(),
            ownershipCompanies: $ownership,
            roleCompanies: $roles,
            crossOwnership: $this->crossOwnershipData,
            structures: $this->structureData,
            properties: $this->propertyData,
            enrichment: $this->enrichmentData,
            expandedNodeIds: $this->expandedNodeIds,
            layers: $layers,
            caps: self::CAPS,
            now: \Carbon\CarbonImmutable::now(),
        );
    }

    public function render()
    {
        return view('metis::livewire.sections.person-structure');
    }
}
