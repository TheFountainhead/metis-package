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

    /** Chip badge counts — derived from the classification, cached as public state. */
    public int $ownershipCount = 0;

    public int $roleCount = 0;

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

        $result = app(RegistryApi::class)->fetchCompaniesByCprCached($this->query);

        if ($this->isFailure($result)) {
            $this->skeletonStatus = 'failed';

            return;
        }

        $this->companiesData = $result['companies'] ?? [];
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

        // Hand fase 2 its queue: every VISIBLE first-level company (ownership
        // roots AND role companies — both must be able to reveal subsidiaries).
        // Truncated companies are deliberately absent; they join the queue only
        // when the user expands past the cap.
        $this->structuresStatus = 'loading';
        $this->structureByCompany = array_fill_keys($this->visibleFirstLevelCvrs(), 'pending');
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

        $next = in_array($layer, $this->layers, true)
            ? array_values(array_diff($this->layers, [$layer]))
            : [...$this->layers, $layer];

        $this->rehydrateBeforeRebuild();

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
        if (! in_array($nodeId, $this->expandedNodeIds, true)) {
            $this->expandedNodeIds[] = $nodeId;
        }

        $this->rehydrateBeforeRebuild();
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

        $result = app(RegistryApi::class)->fetchCrossOwnership($cvrs);

        if ($this->isFailure($result)) {
            return false;
        }

        $this->crossOwnershipData = $result['relationships'] ?? [];

        return true;
    }

    /**
     * RegistryApi's post()-backed calls have TWO failure shapes and both mean
     * the same thing: a plain null, or an array carrying an 'error' key
     * (post() converts a RequestException into ['error' => …, 'status' => …]
     * rather than throwing). Treating the error-array as a successful empty
     * response is exactly the null≠tom bug the spec forbids.
     */
    protected function isFailure(?array $result): bool
    {
        return $result === null || isset($result['error']);
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
     * $companiesData does not survive hydration, so every action that
     * rebuilds re-fetches it first — cache-first, so this is a cache hit
     * (fetchCompaniesByCprCached, 5 min) rather than a fresh API round-trip.
     * A failure here is SWALLOWED rather than flipping the skeleton to
     * 'failed': the skeleton already loaded once this session, and a
     * transient re-fetch error must not regress the section into an error the
     * user never triggered (2a's refreshPropertyData discipline).
     *
     * Cross-ownership is re-fetched in the same breath and under the same
     * gate — without it the demotion would silently un-happen on the next
     * rebuild and a subsidiary would pop back up as a second root.
     */
    protected function rehydrateBeforeRebuild(): void
    {
        if ($this->companiesData !== [] || $this->skeletonStatus !== 'loaded') {
            return;
        }

        $result = app(RegistryApi::class)->fetchCompaniesByCprCached($this->query);

        if ($this->isFailure($result)) {
            return;
        }

        $this->companiesData = $result['companies'] ?? [];
        [$ownership] = $this->classify();
        $this->fetchCrossOwnership($ownership);
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

    protected function rebuild(): void
    {
        $this->graphModel = $this->buildGraph($this->layers);

        [$ownership, $roles] = $this->classify();
        $this->ownershipCount = count($ownership);
        $this->roleCount = count($roles);
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
