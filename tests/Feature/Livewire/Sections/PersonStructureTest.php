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
 * This is the only true hydration boundary available in a test. Neither
 * `->call()` nor a second `Livewire::test()` crosses it: `->call()` reuses the
 * very same PHP object (protected state survives, so a rehydration bug hides),
 * and `Livewire::test()` re-runs mount() from scratch (so rehydration is never
 * exercised at all). Verified empirically against both before writing this.
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

it('drops companies from the fase-2 queue when a layer is switched off', function () {
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

    // The hidden role company is no longer a visible first-level node, so it
    // must leave the queue rather than linger as a stale 'pending' entry.
    expect($queued())->toContain('11111111')
        ->and($queued())->not->toContain('22222222');
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
