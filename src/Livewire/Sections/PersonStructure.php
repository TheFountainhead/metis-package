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
 * This task implements FASE 1 (skeleton) only. Phases 2-4 (structures,
 * properties, enrichment) land in Tasks 7-8 — but every status property they
 * need is DEFINED here, at their 'pending' start value, so the Blade's
 * phase-gating can be written once and never revisited.
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
     * The first-level cvrs that are actually DRAWN — i.e. inside the
     * person_roots / person_roles caps (or revealed by an expand). Read off
     * the built graph rather than recomputed, so the cap/demotion logic lives
     * in exactly one place: the builder.
     */
    protected function visibleFirstLevelCvrs(): array
    {
        return collect($this->graphModel['nodes'])
            ->filter(fn ($n) => ($n['depth'] ?? null) === 1 && ($n['cvr'] ?? null))
            ->pluck('cvr')->unique()->values()->all();
    }

    /**
     * Fase 2's work queue: cvr => status for every VISIBLE first-level company
     * (ownership roots AND role companies — both must be able to reveal
     * subsidiaries). Task 7 consumes this, taking the 'pending' entries 3 at a
     * time.
     *
     * Recomputed from the CURRENT graph on every visibility change, because
     * visibility is not fixed at mount: a chip toggle hides companies (which
     * must leave the queue rather than linger as stale 'pending' work) and a
     * person-root expand reveals companies that were behind the first-level
     * cap (which must join it, or their subsidiaries are never fetched).
     * Truncated companies are deliberately absent until expanded.
     *
     * Statuses already SETTLED by Task 7 are carried over verbatim — a
     * recompute must never reset 'loaded'/'failed' back to 'pending', or every
     * toggle would re-fetch structures the component already holds.
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

        if ($this->companiesData !== []) {
            return true;
        }

        $companies = $this->fetchCompanies();

        if ($companies === null) {
            $this->staleData = true;

            return false;
        }

        // Classify off the freshly-fetched rows rather than the stale property.
        $this->companiesData = $companies;
        [$ownership] = $this->classify();

        if (! $this->fetchCrossOwnership($ownership)) {
            // Drop the half-recovered inputs so no later code path in this same
            // request mistakes them for a complete set.
            $this->companiesData = [];
            $this->crossOwnershipData = [];
            $this->staleData = true;

            return false;
        }

        $this->staleData = false;

        return true;
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
     * chip toggle, expand) ends here, so the fase-2 queue is refreshed here
     * too rather than at each call site — one place that cannot be forgotten.
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
