<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\PersonStructure;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

/**
 * Fakes BOTH fase-1 endpoints in one Http::fake call — they always travel
 * together because Http::fake REPLACES the whole fake map rather than
 * merging into it, so faking one endpoint alone would leave the other
 * unfaked (and a stray real request would escape the test).
 *
 * $companies is the raw companies[] list as the real API returns it (the
 * fields PersonNetwork's classification reads: is_active,
 * has_direct_ownership, roles[] with is_current/title/role/ownership_share).
 *
 * A null argument fakes a hard FAILURE for that endpoint: post() catches the
 * RequestException and returns ['error' => …, 'status' => …] — NOT null — so
 * a 500 here exercises the real production failure contract, which must land
 * the component in 'failed', never 'empty'.
 *
 * The fase-2/3 endpoints are faked here too (as trivially-empty successes),
 * because a component whose per-cvr status says 'loaded' will REFETCH the
 * corresponding payload after a rebuild — protected state does not survive
 * hydration, so a settled status with no recoverable data is reset to
 * 'pending' rather than silently rebuilding a graph missing that layer. Tests
 * that hand-set a 'loaded' status therefore need the endpoint answering, or
 * they would be asserting against a state production can never reach.
 */
function fakeRegistryCpr(?array $companies, ?array $relationships = []): void
{
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => $companies === null
            ? Http::response('Server error', 500)
            : Http::response(['data' => ['companies' => $companies]]),
        '*/v1/cvr/cross-ownership*' => $relationships === null
            ? Http::response('Server error', 500)
            : Http::response(['data' => ['relationships' => $relationships]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
    ]);
}

/** Fase-1 with a FAILING cross-ownership call (companies still succeed). */
function fakeRegistryCrossOwnershipFailure(array $companies): void
{
    fakeRegistryCpr($companies, null);
}

/**
 * Fase-1 that FAILS once and then succeeds — for exercising retrySkeleton().
 *
 * Must be a single Http::sequence(): a second Http::fake() call MERGES its
 * stubs into the existing map rather than replacing them, so the original
 * 500-stub would still match the URL and win, and the retry would appear to
 * fail forever. (Verified against this exact API in isolation — the failure
 * is never cached, so the retry genuinely re-requests.)
 */
function fakeRegistryCprFailingThenSucceeding(array $companies): void
{
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::sequence()
            ->push('Server error', 500)
            ->push(['data' => ['companies' => $companies]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
    ]);
}

/**
 * Rebuilds the component the way a SUBSEQUENT Livewire request does: a brand
 * new instance carrying only the PUBLIC state, with every protected property
 * back at its declared default.
 *
 * CORRECTED in Task 7 (re-probed, because the original claim here drove test
 * design): `->call()` does NOT reuse the same PHP object. A subclass dumping
 * its own protected state from inside a `->call()` handler shows every
 * protected property back at its declared default, on the FIRST call and
 * every call after — so `->call()` does cross a hydration boundary, and the
 * earlier docblock's "protected state survives, so a rehydration bug hides"
 * was wrong.
 *
 * What remains true, and why this helper still exists: `->call()` reconstructs
 * from the LIVEWIRE SNAPSHOT (a JSON round-trip, so 100.0 arrives as 100),
 * while this helper assigns $test->getData() onto a bare `new PersonStructure`.
 * The helper is the sharper instrument — it cannot accidentally carry anything
 * — and it lets a test act on the instance directly rather than through the
 * Testable wrapper. `Livewire::test()` genuinely does not cross the boundary:
 * it re-runs mount() from scratch, so rehydration is never exercised.
 *
 * Returns the fresh instance; act on it directly and assert on its public
 * properties.
 */
function rehydratedFrom(\Livewire\Features\SupportTesting\Testable $test): PersonStructure
{
    $fresh = new PersonStructure;

    foreach ($test->getData() as $property => $value) {
        $fresh->{$property} = $value;
    }

    return $fresh;
}

/** One companies[] row: person OWNS this company (has_direct_ownership). */
function cprOwnershipCompany(string $cvr, ?float $share = 100.0, string $name = 'Holding ApS'): array
{
    return [
        'cvr' => $cvr,
        'name' => $name,
        'company_type' => 'ApS',
        'is_active' => true,
        'has_direct_ownership' => true,
        'roles' => [
            ['is_current' => true, 'role' => 'LEGAL_OWNER', 'title' => null, 'ownership_share' => $share],
        ],
    ];
}

/** One companies[] row: person holds a ROLE only (no direct ownership). */
function cprRoleCompany(string $cvr, ?string $title = 'Direktør', string $name = 'Drift ApS', ?string $role = null): array
{
    return [
        'cvr' => $cvr,
        'name' => $name,
        'company_type' => 'A/S',
        'is_active' => true,
        'has_direct_ownership' => false,
        'roles' => [
            ['is_current' => true, 'role' => $role, 'title' => $title, 'ownership_share' => null],
        ],
    ];
}

it('marks the skeleton failed (not empty) when the cpr lookup fails, and offers a retry', function () {
    fakeRegistryCprFailingThenSucceeding([cprOwnershipCompany('11111111')]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('skeletonStatus'))->toBe('failed');
    $test->assertSee('Prøv igen')
        ->assertDontSee('Ingen aktive selskabsrelationer');

    // The retry re-runs fase 1 — now succeeding — and the section recovers
    // without a remount (the whole point of the retry affordance).
    $test->call('retrySkeleton');

    expect($test->get('skeletonStatus'))->toBe('loaded')
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('11111111');
});

it('treats a successful but empty companies list as empty, with no graph canvas', function () {
    fakeRegistryCpr([]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('skeletonStatus'))->toBe('empty')
        ->and($test->get('graphModel')['nodes'])->toBe([]);

    $test->assertSee('Ingen aktive selskabsrelationer')
        ->assertDontSee('Prøv igen');
});

it('builds a graph with the person root and both layers when the skeleton loads', function () {
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 60.0, 'Lars Holding ApS'),
        cprRoleCompany('22222222', 'Bestyrelsesformand', 'Drift A/S'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('skeletonStatus'))->toBe('loaded');

    $nodes = collect($test->get('graphModel')['nodes']);
    expect($nodes->firstWhere('id', 'person:root'))->not->toBeNull()
        ->and($nodes->firstWhere('id', 'person:root')['kind'])->toBe('person')
        ->and($nodes->pluck('id'))->toContain('11111111', '22222222');

    // The role edge is dashed and carries the role label; the ownership edge
    // is solid with a %-label.
    $edges = collect($test->get('graphModel')['edges']);
    $roleEdge = $edges->first(fn ($e) => $e['to'] === '22222222');
    expect($roleEdge['style'] ?? 'solid')->toBe('dashed')
        ->and($roleEdge['label'])->toBe('Bestyrelsesformand');

    // CPR must NEVER reach the graph payload (node ids, labels, edges).
    expect(json_encode($test->get('graphModel')))->not->toContain('0101011234');
});

it('fails the skeleton when cross-ownership fails, because a de-duped graph would be wrong', function () {
    // Two ownership cvrs → cross-ownership IS called; its failure poisons the
    // whole skeleton rather than silently rendering both companies as roots.
    fakeRegistryCrossOwnershipFailure([
        cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
        cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('skeletonStatus'))->toBe('failed')
        ->and($test->get('graphModel')['nodes'])->toBe([]);
    $test->assertSee('Prøv igen');
});

it('skips the cross-ownership call entirely with fewer than two ownership cvrs', function () {
    fakeRegistryCpr([
        cprOwnershipCompany('11111111'),
        cprRoleCompany('22222222'),
    ]);

    Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cross-ownership'));
});

it('refuses a layer toggle that would leave nothing but the person', function () {
    // ONLY role companies → turning the roles chip off would empty the graph.
    fakeRegistryCpr([
        cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    // Ownership layer is empty, so it can always be switched off.
    $test->call('toggleLayer', 'ownership');
    expect($test->get('layers'))->toBe(['roles']);

    // Roles now carries every visible node — the toggle is rejected outright,
    // leaving state untouched.
    $test->call('toggleLayer', 'roles');
    expect($test->get('layers'))->toBe(['roles'])
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('22222222');
});

it('toggles a layer off and back on, rebuilding the graph each time', function () {
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 100.0, 'Lars Holding ApS'),
        cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $test->call('toggleLayer', 'roles');
    expect($test->get('layers'))->toBe(['ownership'])
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->not->toContain('22222222');
    $test->assertDispatched('graph-refit');

    $test->call('toggleLayer', 'roles');
    expect($test->get('layers'))->toBe(['ownership', 'roles'])
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('22222222');
});

it('shows chip badges counting each layers companies', function () {
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
        cprOwnershipCompany('22222222', 50.0, 'Holding B ApS'),
        cprRoleCompany('33333333', 'Direktør', 'Drift A/S'),
        // Inactive rows are excluded from BOTH counts (PersonNetwork's is_active rule).
        array_merge(cprRoleCompany('44444444', 'Tidligere direktør', 'Lukket ApS'), ['is_active' => false]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('ownershipCount'))->toBe(2)
        ->and($test->get('roleCount'))->toBe(1);

    $test->assertSee('Ejerskab (2)')
        ->assertSee('Roller (1)');
});

it('classifies the role label as title then role, and reads the share off the first current role', function () {
    fakeRegistryCpr([
        // No title → falls back to `role`.
        cprRoleCompany('22222222', null, 'Drift A/S', 'CEO'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $edge = collect($test->get('graphModel')['edges'])->first(fn ($e) => $e['to'] === '22222222');
    expect($edge['label'])->toBe('CEO');
});

it('keeps the raw api payloads out of the wire payload', function () {
    fakeRegistryCpr([cprOwnershipCompany('11111111')]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    // The rendered Livewire snapshot IS the wire payload — the raw companies
    // rows (which carry the person's whole role history) must never appear in
    // it, only the derived graph model.
    $payload = $test->html();
    expect($payload)->not->toContain('has_direct_ownership')
        ->and($payload)->not->toContain('is_current');
});

it('rehydrates the protected companies payload across a real hydration boundary', function () {
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
        cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    // A genuinely FRESH instance (protected state empty), as a second request
    // produces — toggleLayer must re-fetch cache-first before rebuilding, or
    // the graph would collapse to the bare person root.
    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect(collect($fresh->graphModel['nodes'])->pluck('id'))->toContain('11111111');
});

/*
|--------------------------------------------------------------------------
| Degradation across a hydration boundary (fix-round)
|--------------------------------------------------------------------------
| A rebuild is only safe when EVERY builder input was recovered. A partial
| recovery does not produce a smaller graph — it produces a WRONG one, which
| is worse than no update at all. These pin: never rebuild on partial input;
| keep the last-good graphModel; tell the user.
*/

it('keeps the last-good graph when the cross-ownership refetch fails, instead of promoting a child to a root', function () {
    // Parent owns child, so the child is DEMOTED below its parent at mount.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Parent ApS'),
            cprOwnershipCompany('22222222', 100.0, 'Child ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::sequence()
            ->push(['data' => ['relationships' => [
                ['parent_cvr' => '11111111', 'child_cvr' => '22222222', 'ownership_share' => 100.0],
            ]]])
            ->push('Server error', 500),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $edgeAt = fn ($graph) => collect($graph['edges'])->map(fn ($e) => $e['from'].'->'.$e['to'])->all();
    expect($edgeAt($test->get('graphModel')))->toContain('11111111->22222222');

    // Fresh instance + expired cache → the companies refetch succeeds but
    // cross-ownership 500s. Losing the relationships would silently redraw the
    // child as a SECOND ROOT and drop the parent edge: a factually wrong graph.
    \Illuminate\Support\Facades\Cache::flush();
    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect($edgeAt($fresh->graphModel))->toContain('11111111->22222222')
        ->and($fresh->staleData)->toBeTrue();
});

it('keeps the last-good graph when the companies refetch fails on expand', function () {
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::sequence()
            ->push(['data' => ['companies' => [
                cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
                cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
            ]]])
            ->push('Server error', 500),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $before = $test->get('graphModel');

    \Illuminate\Support\Facades\Cache::flush();
    $fresh = rehydratedFrom($test);
    $fresh->expandNode('sub:11111111');

    // Graph preserved verbatim, counts intact, and the staleness surfaced —
    // NOT a silent collapse to a bare person:root with 'loaded' status.
    // toEqual not toBe: getData()'s JSON round-trip coerces 100.0 to 100, which
    // is irrelevant to whether the graph was preserved.
    expect($fresh->graphModel)->toEqual($before)
        ->and($fresh->ownershipCount)->toBe(1)
        ->and($fresh->staleData)->toBeTrue();
});

it('degrades the same way for toggleLayer as for expandNode', function () {
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::sequence()
            ->push(['data' => ['companies' => [
                cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
                cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
            ]]])
            ->push('Server error', 500),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $before = $test->get('graphModel');
    $layersBefore = $test->get('layers');

    \Illuminate\Support\Facades\Cache::flush();
    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    // The toggle is abandoned wholesale — layers unchanged too, so the chips
    // never disagree with the graph they are supposed to describe.
    expect($fresh->graphModel)->toEqual($before)
        ->and($fresh->layers)->toBe($layersBefore)
        ->and($fresh->staleData)->toBeTrue();
});

it('treats a malformed 200 response as a failure rather than a 500', function () {
    // A 200 whose body lacks the 'data' key makes RegistryApi::post() return
    // null from a non-nullable signature → TypeError. Unhandled, that is a
    // white-screen 500 for the user; it must degrade to 'failed' instead.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['unexpected' => true]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('skeletonStatus'))->toBe('failed');
    $test->assertSee('Prøv igen');
});

it('treats a connection exception as a failure rather than a 500', function () {
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('skeletonStatus'))->toBe('failed');
});

it('treats a cross-ownership connection exception as a skeleton failure', function () {
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
            cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('skeletonStatus'))->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| First-level cap expansion (fix-round)
|--------------------------------------------------------------------------
*/

it('reveals every hidden first-level company from a single person-root expand', function () {
    // 25 owned (cap 20 → 5 hidden) + 20 roles (cap 15 → 5 hidden). The builder
    // folds BOTH into one expand.relations count on the root, so the single
    // button the user sees must lift BOTH caps or the number lies.
    $owned = collect(range(1, 25))
        ->map(fn ($i) => cprOwnershipCompany(str_pad((string) $i, 8, '0', STR_PAD_LEFT), 100.0, "Own {$i}"))->all();
    $roles = collect(range(30, 49))
        ->map(fn ($i) => cprRoleCompany(str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'Direktør', "Role {$i}"))->all();

    fakeRegistryCpr(array_merge($owned, $roles));

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $root = collect($test->get('graphModel')['nodes'])->firstWhere('id', 'person:root');
    expect($root['expand']['relations'])->toBe(10)
        ->and($test->get('graphModel')['nodes'])->toHaveCount(36); // root + 20 + 15

    $test->call('expandNode', 'sub:person:root');

    // ONE click reveals all 45 companies; nothing is left behind a cap.
    expect($test->get('graphModel')['nodes'])->toHaveCount(46)
        ->and(collect($test->get('graphModel')['nodes'])->firstWhere('id', 'person:root')['expand'])->toBeNull();
});

it('renders a person-root expand button that targets the node id, never sub:null', function () {
    $owned = collect(range(1, 25))
        ->map(fn ($i) => cprOwnershipCompany(str_pad((string) $i, 8, '0', STR_PAD_LEFT), 100.0, "Own {$i}"))->all();

    fakeRegistryCpr($owned);

    // The person root has cvr=null, so a blade binding built from node.cvr
    // would emit the literal string 'sub:null' and the cap would be
    // unreachable through the UI.
    $html = Livewire::test(PersonStructure::class, ['query' => '0101011234'])->html();

    expect($html)->not->toContain('sub:null')
        ->and($html)->toContain('node.cvr ?? node.id');
});

/*
|--------------------------------------------------------------------------
| Fase-2 queue freshness (fix-round)
|--------------------------------------------------------------------------
*/

it('recomputes the fase-2 queue when an expand reveals new first-level companies', function () {
    $owned = collect(range(1, 25))
        ->map(fn ($i) => cprOwnershipCompany(str_pad((string) $i, 8, '0', STR_PAD_LEFT), 100.0, "Own {$i}"))->all();

    fakeRegistryCpr($owned);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    expect($test->get('structureByCompany'))->toHaveCount(20);

    $test->call('expandNode', 'sub:person:root');

    // The 5 newly revealed roots must join the queue, or Task 7 would never
    // fetch their subsidiaries.
    expect($test->get('structureByCompany'))->toHaveCount(25)
        ->and(array_keys($test->get('structureByCompany')))->toContain('00000025');
});

it('keeps companies in the fase-2 queue when a layer is switched off', function () {
    // REVISED in Task 7. This test originally asserted the OPPOSITE — that a
    // chip-off company leaves the queue — which conflated two different rules
    // and contradicted the spec (§Chips: "Hentning fortsætter uanset
    // chip-tilstand — chips filtrerer kun bygningen"). Probed before changing
    // it: with the old behaviour, switching the roles chip off dropped
    // 22222222 out of the queue entirely, so its subsidiaries were never
    // fetched and switching the chip back on showed a company with a silently
    // missing subtree that no queue would ever repair.
    //
    // TRUNCATION (a company past the first-level cap) is the rule that DOES
    // keep work out of the queue — see the truncation test below.
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
        cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    // NB array_keys() casts numeric-string cvr keys to INTEGERS, so normalise
    // back to strings before comparing.
    $queued = fn () => array_map('strval', array_keys($test->get('structureByCompany')));

    expect($queued())->toContain('11111111', '22222222');

    $test->call('toggleLayer', 'roles');

    // Hidden from the DRAWING, still queued for FETCHING.
    expect($queued())->toContain('11111111', '22222222')
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->not->toContain('22222222');
});

it('preserves already-resolved queue entries when the queue is recomputed', function () {
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
        cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    // Simulate Task 7 having already resolved one cvr, then force a recompute.
    $test->set('structureByCompany', ['11111111' => 'loaded', '22222222' => 'pending']);
    $test->call('toggleLayer', 'roles');
    $test->call('toggleLayer', 'roles');

    // A recompute must not reset settled work back to 'pending' — that would
    // make Task 7 re-fetch a structure it already has on every toggle.
    expect($test->get('structureByCompany')['11111111'])->toBe('loaded');
});

it('reopens a settled fase-2 aggregate when an expand strands new pending entries', function () {
    $owned = collect(range(1, 25))
        ->map(fn ($i) => cprOwnershipCompany(str_pad((string) $i, 8, '0', STR_PAD_LEFT), 100.0, "Own {$i}"))->all();

    fakeRegistryCpr($owned);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    // Simulate Task 7 finishing the phase: every visible cvr settled, aggregate
    // closed.
    $test->set('structureByCompany', collect($test->get('structureByCompany'))->map(fn () => 'loaded')->all())
        ->set('structuresStatus', 'loaded');

    $test->call('expandNode', 'sub:person:root');

    // The 5 newly revealed roots are 'pending', so the aggregate MUST reopen —
    // a Task 7 poll gated on 'loading' would otherwise never fetch them, with
    // no signal that work is stranded.
    $queue = $test->get('structureByCompany');
    expect($queue)->toHaveCount(25)
        ->and(collect($queue)->filter(fn ($s) => $s === 'pending'))->toHaveCount(5)
        ->and($test->get('structuresStatus'))->toBe('loading');
});

it('leaves a settled fase-2 aggregate closed when nothing is pending', function () {
    fakeRegistryCpr([cprOwnershipCompany('11111111', 100.0, 'Holding ApS')]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $test->set('structureByCompany', ['11111111' => 'loaded'])
        ->set('structuresStatus', 'loaded');

    // A rebuild that reveals nothing new must not reopen a finished phase.
    $test->call('toggleLayer', 'roles');

    expect($test->get('structuresStatus'))->toBe('loaded');
});

it('never carries the contradictory staleData + failed skeleton pair', function () {
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::sequence()
            ->push(['data' => ['companies' => [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')]]])
            ->push('Server error', 500)
            ->push('Server error', 500),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    \Illuminate\Support\Facades\Cache::flush();
    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');
    expect($fresh->staleData)->toBeTrue();

    // The retry also fails. 'failed' means there is no graph at all, so a
    // "showing the last known view" note alongside it is a contradiction —
    // the invariant must hold in STATE, not merely by Blade nesting.
    $fresh->retrySkeleton();

    expect($fresh->skeletonStatus)->toBe('failed')
        ->and($fresh->staleData)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Fase 2 (strukturer) + fase 3 (ejendomme) — Task 7
|--------------------------------------------------------------------------
| One tick() drives both phases. Everything below pins the BUDGETS (3 cvrs
| per tick per phase, 24 shared property attempts), the per-cvr independence
| (one failure never blocks the rest or the next phase) and the retry
| cascade. Note the queue is deliberately layer-INDEPENDENT: chips filter
| the BUILD, never the fetching (spec §Chips).
*/

/**
 * Full fase-1 + fase-2 + fase-3 fake in ONE Http::fake (the map is replaced,
 * not merged — see fakeRegistryCpr's docblock).
 *
 * $structures: cvr => subsidiaries-payload, or null for a per-cvr FAILURE
 * (fetchCompanyStructuresPooled maps a non-2xx response to null for that cvr).
 * $portfolios: cvr => list of portfolio rows, or 'building' for the
 * empty-list-but-nonzero-count response that means "backend still building",
 * or null for a hard failure.
 */
function fakePersonPhases(array $companies, array $structures = [], array $portfolios = [], array $relationships = []): void
{
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => $companies]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => $relationships]]),
        '*/v1/cvr/company-structure*' => function ($request) use ($structures) {
            $cvr = $request->data()['cvr'] ?? null;
            // array_key_exists, NOT ?? — a null VALUE means "fail this cvr",
            // and `null ?? $default` would silently hand back the default,
            // turning every intended per-cvr failure into a success.
            $payload = array_key_exists($cvr, $structures) ? $structures[$cvr] : ['subsidiaries' => []];

            return $payload === null
                ? Http::response('Server error', 500)
                : Http::response(['data' => $payload]);
        },
        '*/property-portfolio*' => function ($request) use ($portfolios) {
            preg_match('#/company/(\d+)/property-portfolio#', $request->url(), $m);
            $cvr = $m[1] ?? '';
            $rows = array_key_exists($cvr, $portfolios) ? $portfolios[$cvr] : [];

            if ($rows === null) {
                return Http::response('Server error', 500);
            }

            // 'building' = a successful response whose list is empty while the
            // count is non-zero: the backend is still assembling the portfolio.
            if ($rows === 'building') {
                return Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 7]]]);
            }

            return Http::response(['data' => ['portfolio' => [
                'properties' => $rows,
                'property_count' => count($rows),
                'total_count' => count($rows),
            ]]]);
        },
        '*/properties/batch*' => Http::response(['data' => []]),
    ]);
}

/** One subsidiary payload row as company-structure returns it. */
function personSubsidiary(string $cvr, string $name = 'Datter ApS', float $share = 100.0): array
{
    return ['cvr' => $cvr, 'name' => $name, 'ownership_share' => $share, 'children' => []];
}

/** One portfolio row hanging off $ownerCvr. */
function personPortfolioRow(string $ownerCvr, string $matrikelId): array
{
    return [
        'owner_cvr' => $ownerCvr,
        'matrikel_id' => $matrikelId,
        'is_matriculated' => true,
        'address' => 'Testvej '.$matrikelId,
        'city' => 'Testby',
    ];
}

it('takes three structure cvrs per tick and leaves the rest pending', function () {
    $companies = collect(range(1, 5))
        ->map(fn ($i) => cprOwnershipCompany(str_pad((string) $i, 8, '0', STR_PAD_LEFT), 100.0, "Own {$i}"))->all();

    fakePersonPhases($companies, [
        '00000001' => ['subsidiaries' => [personSubsidiary('90000001')]],
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $test->call('tick');

    $queue = collect($test->get('structureByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s]);
    expect($queue->filter(fn ($s) => $s === 'loaded')->keys()->all())
        ->toBe(['00000001', '00000002', '00000003'])
        ->and($queue->filter(fn ($s) => $s === 'pending')->keys()->all())
        ->toBe(['00000004', '00000005'])
        ->and($test->get('structuresStatus'))->toBe('loading');

    // The fetched subsidiary is IN the graph after the tick — the tick rebuilds
    // (structures change the graph, so this tick is not gratuitous).
    expect(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('90000001');

    // Second tick drains the remaining two and closes the aggregate.
    $test->call('tick');

    expect(collect($test->get('structureByCompany'))->filter(fn ($s) => $s === 'pending'))->toHaveCount(0)
        ->and($test->get('structuresStatus'))->toBe('loaded');
});

it('marks only the failing cvr failed and still finishes the phase', function () {
    fakePersonPhases([
        cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
        cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
    ], [
        '11111111' => null, // hard failure for this cvr only
        '22222222' => ['subsidiaries' => [personSubsidiary('90000002')]],
    ], relationships: []);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    $queue = collect($test->get('structureByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s]);
    expect($queue['11111111'])->toBe('failed')
        ->and($queue['22222222'])->toBe('loaded')
        // ≥1 failure ⇒ the aggregate is 'failed' (the note + retryStructures()),
        // but the healthy cvr's subsidiaries are in the graph regardless.
        ->and($test->get('structuresStatus'))->toBe('failed')
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('90000002');

    $test->assertSee('Prøv igen');
});

it('starts fase 3 even when some fase-2 cvrs failed', function () {
    fakePersonPhases([
        cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
        cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
    ], [
        '11111111' => null,
    ], [
        '11111111' => [personPortfolioRow('11111111', '5001')],
        '22222222' => [personPortfolioRow('22222222', '5002')],
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $test->call('tick'); // fase 2 settles (one failed) → fase 3 seeded
    expect($test->get('structuresStatus'))->toBe('failed')
        ->and($test->get('propertiesStatus'))->toBe('building')
        // A failed structure cvr is still a first-level company that can own
        // properties — it must NOT be excluded from the fase-3 queue.
        ->and(collect($test->get('propertiesByCompany'))->filter(fn ($s) => $s === 'pending'))->toHaveCount(2);

    $test->call('tick'); // fase 3 drains both

    expect($test->get('propertiesStatus'))->toBe('loaded')
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))
        ->toContain('bfe:5001', 'bfe:5002');
});

it('fails the properties phase when the shared attempts budget is exhausted', function () {
    // One company whose portfolio answers 'building' forever. Each attempt
    // burns one of the 24 shared budget units.
    fakePersonPhases(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => 'building'],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles, fase 3 seeded

    expect($test->get('propertiesStatus'))->toBe('building');

    // 24 attempts, then the phase gives up rather than spinning forever.
    for ($i = 0; $i < 24; $i++) {
        $test->call('tick');
    }

    expect($test->get('propertiesAttempts'))->toBe(24)
        ->and($test->get('propertiesStatus'))->toBe('failed');

    $test->assertSee('Ejendomme kunne ikke hentes');

    // retryProperties() resets the budget so the phase can run again.
    $test->call('retryProperties');

    expect($test->get('propertiesAttempts'))->toBe(0)
        ->and($test->get('propertiesStatus'))->toBe('building');
});

it('cascades a structures retry into fase 3 and enrichment', function () {
    fakePersonPhases([
        cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
        cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
    ], [
        '11111111' => null,
    ], [
        '11111111' => [personPortfolioRow('11111111', '5001')],
        '22222222' => [personPortfolioRow('22222222', '5002')],
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles with one failure, fase 3 seeded
    $test->call('tick'); // fase 3 drains
    $test->set('enrichmentStatus', 'loaded');

    expect($test->get('propertiesStatus'))->toBe('loaded');

    $test->call('retryStructures');

    // The failed cvr goes back to 'pending' — and so does everything
    // downstream FOR THAT CVR: a retry can reveal a whole new subtree whose
    // properties and enrichment were never fetched.
    $queue = collect($test->get('structureByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s]);
    expect($queue['11111111'])->toBe('pending')
        // The cvr that already succeeded is NOT re-fetched.
        ->and($queue['22222222'])->toBe('loaded')
        ->and($test->get('structuresStatus'))->toBe('loading')
        ->and(collect($test->get('propertiesByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s])['11111111'])
        ->toBe('pending')
        ->and($test->get('propertiesStatus'))->toBe('pending')
        ->and($test->get('enrichmentStatus'))->toBe('pending');
});

it('keeps fetching structures for a layer whose chip is switched off', function () {
    // Spec §Chips: "Hentning fortsætter uanset chip-tilstand — chips filtrerer
    // kun bygningen". A role company hidden behind a chip must stay in the
    // fase-2 queue, or re-enabling the chip would show a company whose
    // subsidiaries were silently never fetched.
    fakePersonPhases([
        cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
        cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
    ], [
        '22222222' => ['subsidiaries' => [personSubsidiary('90000022')]],
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $test->call('toggleLayer', 'roles');

    $queued = fn () => array_map('strval', array_keys($test->get('structureByCompany')));
    expect($queued())->toContain('11111111', '22222222');

    $test->call('tick');

    // Fetched while hidden. Switching the chip back on shows the subsidiary
    // immediately — no second fetch.
    expect(collect($test->get('graphModel')['nodes'])->pluck('id'))->not->toContain('90000022');

    $test->call('toggleLayer', 'roles');

    expect(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('22222222', '90000022');
});

it('never queues a truncated first-level company until it is expanded', function () {
    // 25 owned, cap 20 → 5 truncated. Truncated companies are NOT drawn, so
    // fetching their structures would be work no one asked for.
    $companies = collect(range(1, 25))
        ->map(fn ($i) => cprOwnershipCompany(str_pad((string) $i, 8, '0', STR_PAD_LEFT), 100.0, "Own {$i}"))->all();

    fakePersonPhases($companies, [
        '00000025' => ['subsidiaries' => [personSubsidiary('90000025')]],
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('structureByCompany'))->toHaveCount(20)
        ->and(array_map('strval', array_keys($test->get('structureByCompany'))))->not->toContain('00000025');

    $test->call('expandNode', 'sub:person:root');

    expect(array_map('strval', array_keys($test->get('structureByCompany'))))->toContain('00000025');
});

it('polls only while there is work left', function () {
    fakePersonPhases(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => []], // successful, but no properties
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    expect($test->html())->toContain('wire:poll');

    $test->call('tick'); // fase 2 done → fase 3 seeded
    $test->call('tick'); // fase 3 done (empty)

    expect($test->get('structuresStatus'))->toBe('loaded')
        ->and($test->get('propertiesStatus'))->toBe('empty');

    // Nothing left to do: an ungated wire:poll would keep hitting the server
    // for the rest of the page's life.
    expect($test->html())->not->toContain('wire:poll');
});

it('leaves the graph untouched on a properties tick that only burned budget', function () {
    // A 'building' response writes nothing the builder reads, so the tick
    // skips rebuild() — pure churn otherwise, up to 24 times.
    //
    // ⚠️ HONEST LIMIT of this test: it pins the OUTCOME (graph unchanged,
    // budget advanced, phase still building), not the skipped rebuild()
    // itself. Mutation-tested — removing the `if ($wrote)` guard keeps all
    // tests green, because a rebuild on unchanged inputs is deterministic and
    // therefore produces a byte-identical graph. The guard is an efficiency
    // measure with no observable state signature; do not mistake this test
    // for a pin on it.
    fakePersonPhases(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => 'building'],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles

    $before = $test->get('graphModel');
    $sentBefore = Http::recorded()->count();

    $test->call('tick'); // 'building' only

    // The graph is byte-identical: nothing was written, so nothing rebuilt.
    // Exactly ONE request — the portfolio call. The fase-2 recovery that also
    // runs on this tick is a CACHE HIT, because fetchCompanyStructuresPooled
    // warms the very key fetchCompanyStructureCached reads (fix-round);
    // before that warming it was a second real POST, every tick, per cvr.
    expect($test->get('graphModel'))->toEqual($before)
        ->and($test->get('propertiesAttempts'))->toBe(1)
        ->and(Http::recorded()->count())->toBe($sentBefore + 1)
        ->and($test->get('propertiesStatus'))->toBe('building');
});

it('is a no-op tick once every phase has settled', function () {
    fakePersonPhases(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => [personPortfolioRow('11111111', '5001')]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');
    $test->call('tick');

    $before = Http::recorded()->count();
    $test->call('tick');

    expect(Http::recorded()->count())->toBe($before);
});

it('keeps fase-2 results across a hydration boundary by refetching them', function () {
    fakePersonPhases(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        ['11111111' => ['subsidiaries' => [personSubsidiary('90000011')]]],
        ['11111111' => [personPortfolioRow('11111111', '5001')]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 loads 11111111, seeds fase 3

    expect(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('90000011');

    // Next poll tick = a fresh instance. The already-'loaded' structure lives
    // only in protected state, so it must be recovered — otherwise the fase-3
    // tick would rebuild a graph that has LOST the subsidiary it just showed.
    $fresh = rehydratedFrom($test);
    $fresh->tick();

    expect(collect($fresh->graphModel['nodes'])->pluck('id'))
        ->toContain('11111111', '90000011', 'bfe:5001')
        ->and($fresh->propertiesStatus)->toBe('loaded');
});

/*
|--------------------------------------------------------------------------
| Recovery-path integrity (fix-round)
|--------------------------------------------------------------------------
| recoverPhaseResults() can DOWNGRADE a settled cvr back to 'pending'. Every
| downgrade must leave the aggregates consistent with the per-cvr maps, or
| the poll gate — which reads the aggregates — switches off over work that
| is still queued. Silent and permanent: the queue never drains and no retry
| button is ever shown.
*/

it('re-derives the aggregate when recovery downgrades a structure cvr', function () {
    // A 200 whose data is [] is NON-NULL, so the pooled fetch settles the cvr
    // 'loaded' — but fetchCompanyStructureCached returns [] for it, so the
    // next tick's recovery cannot restore anything and must downgrade.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => []]),
        // Properties settle EMPTY so fase 3 closes: the poll's only remaining
        // reason to exist is fase 2's aggregate.
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles 'loaded', fase 3 seeded
    $test->call('tick'); // recovery downgrades 11111111 back to 'pending'

    $queue = collect($test->get('structureByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s]);

    // Probed before the fix: queue='pending' but aggregate='loaded' and the
    // poll was GONE from the HTML — the cvr could never be fetched again.
    expect($queue['11111111'])->toBe('pending')
        ->and($test->get('structuresStatus'))->toBe('loading')
        ->and($test->html())->toContain('wire:poll');
});

it('re-derives the aggregate when recovery downgrades a property cvr', function () {
    // The portfolio loads once, then answers 'building' forever — so recovery
    // on the next tick cannot restore the rows and must downgrade the cvr.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::sequence()
            ->push(['data' => ['portfolio' => ['properties' => [personPortfolioRow('11111111', '5001')], 'property_count' => 1]]])
            ->pushStatus(500),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles
    $test->call('tick'); // fase 3 loads the portfolio

    expect($test->get('propertiesStatus'))->toBe('loaded');

    \Illuminate\Support\Facades\Cache::flush();
    $test->call('tick'); // recovery cannot restore the rows

    // Whatever the downgrade decides, the aggregate must AGREE with the map —
    // never a closed phase sitting on unsettled per-cvr work.
    $queue = collect($test->get('propertiesByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s]);
    $unsettled = collect($queue)->contains(fn ($s) => in_array($s, ['pending', 'building'], true));

    expect($unsettled ? in_array($test->get('propertiesStatus'), ['building', 'failed'], true) : true)->toBeTrue()
        ->and($unsettled ? str_contains($test->html(), 'wire:poll') || $test->get('propertiesStatus') === 'failed' : true)
        ->toBeTrue();
});

it('keeps a failed property cvr failed instead of silently retrying it', function () {
    // MINOR: recovery collapsed failed / 'building' / genuinely-empty into one
    // 'pending' downgrade. A cvr the phase already gave up on must not be
    // quietly re-queued by a recovery pass — that is retryProperties()' job.
    fakePersonPhases(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => null], // portfolio hard-fails
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles
    $test->call('tick'); // fase 3: the cvr fails

    expect(collect($test->get('propertiesByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s])['11111111'])
        ->toBe('failed')
        ->and($test->get('propertiesStatus'))->toBe('failed');

    $test->call('tick'); // a further tick must not resurrect it

    expect(collect($test->get('propertiesByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s])['11111111'])
        ->toBe('failed');

    // Only an explicit retry re-queues it.
    $test->call('retryProperties');

    expect(collect($test->get('propertiesByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s])['11111111'])
        ->toBe('pending');
});

it('does not let a recovery downgrade bypass the shared attempts budget', function () {
    // MINOR: a downgrade that re-enters the 'building' path must consume
    // budget like any other attempt, or a portfolio that loads once and then
    // answers 'building' forever polls without limit — the exact unbounded
    // spin MAX_PROPERTIES_ATTEMPTS exists to stop.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 7]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles

    for ($i = 0; $i < 30; $i++) {
        $test->call('tick');
    }

    // Bounded, and terminal: never an unbounded spin.
    expect($test->get('propertiesAttempts'))->toBeLessThanOrEqual(24)
        ->and($test->get('propertiesStatus'))->toBe('failed');
});

it('stops issuing portfolio requests once the budget is spent', function () {
    // The budget must gate the WORK, not just the STATUS. tick() gates on the
    // queue (as it must), and an exhausted budget leaves cvrs 'pending' there
    // forever — so without a hard gate in tickProperties() every further tick
    // keeps fetching behind an aggregate that already reads 'failed'.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 7]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles

    for ($i = 0; $i < 24; $i++) {
        $test->call('tick');
    }

    expect($test->get('propertiesStatus'))->toBe('failed');

    $spent = Http::recorded()->count();
    $test->call('tick');
    $test->call('tick');

    // Not one more request after the budget is gone.
    expect(Http::recorded()->count())->toBe($spent);
});

it('never counts the shared budget past its own ceiling', function () {
    // Several cvrs burn several units per tick, so the counter advances in
    // steps rather than one at a time — the hard gate must still land it ON
    // the ceiling, never past it. (Probed: with CVRS_PER_TICK=3 the steps
    // divide 24 exactly; this pins that the gate holds for a multi-cvr queue,
    // which is the shape where an overshoot would appear first.)
    fakePersonPhases(
        [
            cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
            cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
            cprOwnershipCompany('33333333', 100.0, 'Holding C ApS'),
        ],
        [],
        [
            '11111111' => 'building',
            '22222222' => 'building',
            '33333333' => 'building',
        ],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles

    // 3 cvrs burn 3 units per tick — 9 ticks would reach 27 uncapped.
    for ($i = 0; $i < 12; $i++) {
        $test->call('tick');
    }

    expect($test->get('propertiesAttempts'))->toBe(24)
        ->and($test->get('propertiesStatus'))->toBe('failed');
});
