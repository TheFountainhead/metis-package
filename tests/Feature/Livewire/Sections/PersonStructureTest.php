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

    // 'empty' is provisional at this point: the private-properties layer has
    // not been consulted yet, so the section shows a shimmer + poll, NOT the
    // empty message — declaring "ingen" before the last layer has answered
    // would be a lie for a person who owns property privately.
    expect($test->get('skeletonStatus'))->toBe('empty')
        ->and($test->get('privatePropertiesStatus'))->toBe('pending')
        ->and($test->get('graphModel')['nodes'])->toBe([]);

    $test->assertDontSee('Ingen aktive selskabsrelationer');

    // fakeRegistryCpr's property-portfolio wildcard answers the person call
    // with a company-shaped body → no personal_properties key → 'empty'. Only
    // NOW is the verdict final and the message on screen.
    $test->call('tick');

    expect($test->get('skeletonStatus'))->toBe('empty')
        ->and($test->get('privatePropertiesStatus'))->toBe('empty');

    $test->assertSee('Ingen aktive selskabsrelationer')
        ->assertDontSee('Prøv igen');
});

it('promotes an empty skeleton to a graph when private properties land on the tick', function () {
    // Zero ACTIVE companies (fase 1 settles 'empty') but one private property:
    // the poll must still run the private phase, and rows landing must promote
    // the skeleton — found live 28/7, where the empty-state hid a person's
    // private property because the poll only existed under 'loaded'.
    fakePersonPrivate([], [privatePropertyRow()]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('skeletonStatus'))->toBe('empty');

    $test->call('tick');

    expect($test->get('skeletonStatus'))->toBe('loaded')
        ->and($test->get('privatePropertiesStatus'))->toBe('loaded')
        ->and(collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(1)
        ->and($test->get('layers'))->toContain('private_properties');

    $test->assertDontSee('Ingen aktive selskabsrelationer');
});

it('keeps the empty skeleton with a retry when the private fetch fails, and promotes on retry', function () {
    Http::fake([
        '*/v1/person/property-portfolio*' => Http::sequence()
            ->push('Server error', 500)
            ->push(['data' => ['personal_properties' => [privatePropertyRow()]]]),
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => []]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    // null ≠ tom: the failed fetch must not silently pass as "no private
    // properties either" — the empty message shows, but WITH the phase's own
    // retry affordance beside it.
    expect($test->get('skeletonStatus'))->toBe('empty')
        ->and($test->get('privatePropertiesStatus'))->toBe('failed');

    $test->assertSee('Ingen aktive selskabsrelationer')
        ->assertSee('Private ejendomme kunne ikke hentes.');

    // The retry runs the same promotion path loadPrivateProperties() owns.
    $test->call('retryPrivateProperties');

    expect($test->get('skeletonStatus'))->toBe('loaded')
        ->and(collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(1);
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

    // Ownership layer is empty, so it can always be switched off. (The
    // private-properties layer is empty too here — fakeRegistryCpr does not
    // fake the person-portfolio endpoint, so that phase never loads a row.)
    $test->call('toggleLayer', 'ownership');
    expect($test->get('layers'))->toBe(['roles', 'private_properties']);

    // Roles now carries every visible node — the toggle is rejected outright,
    // leaving state untouched.
    $test->call('toggleLayer', 'roles');
    expect($test->get('layers'))->toBe(['roles', 'private_properties'])
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('22222222');
});

it('locks the only chip that carries nodes even while the empty chips are still active', function () {
    // The affordance must agree with the SERVER rule. An ownership-only person
    // has roleCount 0 and every chip active, so count($layers) === 1 is false
    // and the Ejerskab chip rendered ENABLED — while toggleLayer() refuses the
    // click outright. A button that looks live and silently does nothing is
    // worse than a disabled one: the user reads it as a broken graph. (The
    // third layer only widens the gap: two empty chips, not one.)
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('ownershipCount'))->toBe(1)
        ->and($test->get('roleCount'))->toBe(0)
        ->and($test->get('layers'))->toBe(['ownership', 'roles', 'private_properties']);

    // The chip that carries every node is locked…
    //
    // Matched as the STANDALONE `disabled` attribute rather than the substring:
    // every chip now also carries wire:loading.attr="disabled" (the round-trip
    // affordance), which contains the word but is not the lock. A substring
    // match would call every chip locked and the assertion would stop meaning
    // anything.
    $ownershipChip = chipMarkupFor($test->html(), 'ownership');
    expect($ownershipChip)->toMatch('/\sdisabled(?=[\s>])/')
        ->and($ownershipChip)->toContain('mgraph-chip--locked');

    // …while the empty one stays clickable: switching it off removes nothing.
    $rolesChip = chipMarkupFor($test->html(), 'roles');
    expect($rolesChip)->not->toMatch('/\sdisabled(?=[\s>])/')
        ->and($rolesChip)->not->toContain('mgraph-chip--locked');

    // And the server agrees — the affordance is describing a real refusal.
    $test->call('toggleLayer', 'ownership');
    expect($test->get('layers'))->toBe(['ownership', 'roles', 'private_properties']);
});

/** The single <button> element for one chip, sliced out of the rendered HTML. */
function chipMarkupFor(string $html, string $layer): string
{
    $start = strpos($html, "toggleLayer('{$layer}')");

    expect($start)->not->toBeFalse("chip for layer [{$layer}] not rendered");

    $open = strrpos(substr($html, 0, $start), '<button');
    $close = strpos($html, '</button>', $start);

    return substr($html, $open, $close - $open);
}

it('toggles a layer off and back on, rebuilding the graph each time', function () {
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 100.0, 'Lars Holding ApS'),
        cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $test->call('toggleLayer', 'roles');
    expect($test->get('layers'))->toBe(['ownership', 'private_properties'])
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->not->toContain('22222222');
    $test->assertDispatched('graph-refit');

    $test->call('toggleLayer', 'roles');
    expect($test->get('layers'))->toBe(['ownership', 'private_properties', 'roles'])
        ->and(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('22222222');
});

it('dispatches graph-refit after a node expand — the expand path must re-frame like a chip toggle', function () {
    // Re-review New-1: kun toggleLayer dispatchede refit; et udvid der vokser
    // grafen ud over viewporten efterlod nye noder klippet uden for frame.
    fakeRegistryCpr(array_map(
        fn (int $i) => cprOwnershipCompany(str_pad((string) $i, 8, '9', STR_PAD_LEFT), 10.0, "Selskab {$i}"),
        range(1, 25),
    ));

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $test->call('expandNode', 'sub:person:root');
    $test->assertDispatched('graph-refit');
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
    // The cache warms with it: a 'loaded' cvr whose structure is no longer
    // recoverable is downgraded by the (cache-only) recovery pass, which would
    // hide the bookkeeping rule this test is about behind a different one.
    warmStructureCache(['11111111']);
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
    // closed — with the cache warm, as a real completed phase leaves it, so the
    // cache-only recovery has something to reclaim.
    warmStructureCache(array_map('strval', array_keys($test->get('structureByCompany'))));
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

    warmStructureCache(['11111111']);
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

/**
 * Warms the 5-min structure cache for $cvrs, the way a completed fase-2 tick
 * would have (fetchCompanyStructuresPooled writes it).
 *
 * Needed by any test that HAND-SETS a structure status to 'loaded' without
 * having run the phase: recovery is CACHE-ONLY, so a 'loaded' cvr with a cold
 * cache is downgraded straight back to 'pending' — correctly. Before the
 * cache-only fix those tests passed on a silent real POST inside the
 * interactive request, which is the very fetch storm the fix removes.
 */
function warmStructureCache(array $cvrs, array $structure = ['subsidiaries' => []]): void
{
    foreach ($cvrs as $cvr) {
        \Illuminate\Support\Facades\Cache::put("metis:company_structure:{$cvr}", $structure, 300);
    }
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

    // Task 8: fase 3 settling is no longer the end — fase 4 (enrichment) runs
    // on the SAME poll, so the gate must still be open here or the phase would
    // be stranded with nothing left to trigger it. It closes one tick later.
    expect($test->html())->toContain('wire:poll')
        ->and($test->get('enrichmentStatus'))->toBe('pending');

    $test->call('tick'); // fase 4

    expect($test->get('enrichmentStatus'))->toBe('loaded');

    // NOW there is nothing left to do: an ungated wire:poll would keep hitting
    // the server for the rest of the page's life.
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
    $test->call('tick'); // fase 2
    $test->call('tick'); // fase 3
    // Task 8: fase 4 settles on the third tick (the pooled company-info call
    // plus the in-graph properties/batch call), so the no-op tick this test is
    // about is the FOURTH — before that there was still work left.
    $test->call('tick'); // fase 4

    expect($test->get('enrichmentStatus'))->toBe('loaded');

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
    // The portfolio loads once; the cache is then evicted, so the next tick's
    // CACHE-ONLY recovery cannot restore the rows and must downgrade the cvr.
    //
    // REWRITTEN in the fix round. The old fixture answered 500 on the second
    // call, which settled the cvr 'failed' — leaving every assertion behind a
    // `$unsettled ? … : true` conditional that passed VACUOUSLY, so the test
    // pinned nothing. The scenario now genuinely downgrades, and the expected
    // state is asserted outright.
    fakePersonPhases(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => [personPortfolioRow('11111111', '5001')]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles
    $test->call('tick'); // fase 3 loads the portfolio

    expect($test->get('propertiesStatus'))->toBe('loaded');

    \Illuminate\Support\Facades\Cache::flush();
    $test->call('tick'); // recovery cannot restore the rows

    // The cvr is back in the queue — and the AGGREGATE re-derived with it, so
    // the poll gate is still on screen to drain it. A closed phase over an
    // unsettled map is the failure this pins: silent and permanent.
    $queue = collect($test->get('propertiesByCompany'))->mapWithKeys(fn ($s, $c) => [(string) $c => $s]);

    expect($queue['11111111'])->toBe('pending')
        ->and($test->get('propertiesStatus'))->toBe('building')
        ->and($test->html())->toContain('wire:poll')
        // The miss cost nothing: a cache read is not an attempt.
        ->and($test->get('propertiesAttempts'))->toBe(0);
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

it('never fetches fase-2/3 payloads from inside an interactive request, however cold the cache', function () {
    // 🚨 Spec §Rehydrering: an interactive request may not fetch more than one
    // tick's batch. The fase-2/3 recovery used to issue a REAL sequential POST
    // per settled cvr on a cache miss — so a chip toggle on a person with N
    // companies cost N structure POSTs plus N portfolio GETs, unbounded by
    // CVRS_PER_TICK, inside a click. Cold cache is the ordinary case: the
    // structure/portfolio caches live 5 minutes, the page does not.
    fakePersonPhases(
        [
            cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
            cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
            cprOwnershipCompany('33333333', 100.0, 'Holding C ApS'),
        ],
        [
            '11111111' => ['subsidiaries' => [personSubsidiary('90000011')]],
            '22222222' => ['subsidiaries' => [personSubsidiary('90000022')]],
            '33333333' => ['subsidiaries' => [personSubsidiary('90000033')]],
        ],
        [
            '11111111' => [personPortfolioRow('11111111', '5001')],
            '22222222' => [personPortfolioRow('22222222', '5002')],
            '33333333' => [personPortfolioRow('33333333', '5003')],
        ],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    expect($test->get('structuresStatus'))->toBe('loaded')
        ->and($test->get('propertiesStatus'))->toBe('loaded');

    // Every cache the recovery reads is gone — the state of the world after
    // five idle minutes on an open tab.
    \Illuminate\Support\Facades\Cache::flush();

    $before = Http::recorded()->count();

    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    $sent = Http::recorded()->skip($before)->map(fn ($pair) => $pair[0]->url());

    expect($sent->filter(fn ($url) => str_contains($url, 'company-structure')))->toBeEmpty()
        ->and($sent->filter(fn ($url) => str_contains($url, 'property-portfolio')))->toBeEmpty();

    // The work is not lost — it is handed back to the poll loop.
    expect(collect($fresh->structureByCompany)->every(fn ($s) => $s === 'pending'))->toBeTrue()
        ->and(collect($fresh->propertiesByCompany)->every(fn ($s) => $s === 'pending'))->toBeTrue()
        ->and($fresh->structuresStatus)->toBe('loading')
        ->and(in_array($fresh->propertiesStatus, ['pending', 'building'], true))->toBeTrue();

    // …and the poll gate is on screen to run it. Asserted on a Testable
    // carrying the recovered state, because the Blade reads $this->graphModel
    // and so cannot be rendered off a bare instance.
    $rendered = Livewire::test(PersonStructure::class, ['query' => '0101011234'])
        ->set('structureByCompany', $fresh->structureByCompany)
        ->set('structuresStatus', $fresh->structuresStatus)
        ->set('propertiesByCompany', $fresh->propertiesByCompany)
        ->set('propertiesStatus', $fresh->propertiesStatus);

    expect($rendered->html())->toContain('wire:poll');
});

it('does not charge the shared properties budget for a recovery miss', function () {
    // A recovery read is not an ATTEMPT: nothing was fetched, so nothing may be
    // billed. Charging it let a page that merely sat open through a few chip
    // toggles exhaust MAX_PROPERTIES_ATTEMPTS and settle 'failed' over
    // portfolios that had never once answered 'building'.
    fakePersonPhases(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => [personPortfolioRow('11111111', '5001')]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    expect($test->get('propertiesStatus'))->toBe('loaded')
        ->and($test->get('propertiesAttempts'))->toBe(0);

    \Illuminate\Support\Facades\Cache::flush();

    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect($fresh->propertiesAttempts)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Fase 4 (berigelse) — Task 8
|--------------------------------------------------------------------------
| Enrichment is graph-wide, not per-cvr: one pooled company-info call for the
| ENRICHABLE nodes actually in the graph, one properties/batch call for the
| property nodes actually in the graph, then streetview URLs layered on top.
|
| The whole resolution half of that (companyEnrichmentFromInfo /
| propertyEnrichmentFromBatch / attachStreetviewUrls) is SHARED with 2a's
| CompanyStructure through the ResolvesGraphEnrichment trait — 2a's own suite
| is the proof the extraction was behaviour-preserving, which is why those
| tests were not touched. The two financials-unit pins below are ported here
| verbatim in intent, so the shared rule is pinned from BOTH components: a
| future edit that "fixes" the unit for one caller breaks the other's test.
*/

/**
 * Fase-1..4 fake. Extends fakePersonPhases with a per-cvr company-info
 * endpoint (the pooled enrichment call) and a properties/batch handler that
 * ECHOES BACK the matrikel_ids it was asked for, so a test can assert on the
 * payload the component actually sent rather than on a canned response.
 *
 * $companyInfo: cvr => company payload (financials/contact/etc).
 * $batchExtra: matrikel_id => extra fields merged into that property's batch row.
 */
function fakePersonEnrichment(
    array $companies,
    array $structures = [],
    array $portfolios = [],
    array $companyInfo = [],
    array $batchExtra = [],
    array $relationships = [],
): void {
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => $companies]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => $relationships]]),
        '*/v1/cvr/company-structure*' => function ($request) use ($structures) {
            $cvr = $request->data()['cvr'] ?? null;
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

            if ($rows === 'building') {
                return Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 7]]]);
            }

            return Http::response(['data' => ['portfolio' => [
                'properties' => $rows,
                'property_count' => count($rows),
                'total_count' => count($rows),
            ]]]);
        },
        '*/properties/batch*' => function ($request) use ($batchExtra) {
            $ids = $request->data()['matrikel_ids'] ?? [];

            return Http::response(['data' => collect($ids)->map(fn ($mid) => array_merge([
                'matrikel_id' => $mid,
                'bbr' => ['buildings' => [['usage' => 130, 'total_area' => 150]]],
            ], $batchExtra[$mid] ?? []))->all()]);
        },
        // Specific-before-generic does not apply — Http::fake matches in
        // insertion order, so the per-cvr company endpoint must come AFTER the
        // structure/portfolio patterns (which are more specific paths) but
        // still match '/v1/cvr/company/<cvr>'.
        '*/v1/cvr/company/*' => function ($request) use ($companyInfo) {
            preg_match('#/v1/cvr/company/(\d+)#', $request->url(), $m);
            $cvr = $m[1] ?? '';

            if (! array_key_exists($cvr, $companyInfo)) {
                return Http::response('Not found', 404);
            }

            return Http::response(['data' => ['company' => array_merge(['cvr' => $cvr], $companyInfo[$cvr])]]);
        },
    ]);
}

/**
 * Runs tick() until every phase has settled (bounded). The stop condition is
 * deliberately the SAME predicate the Blade's wire:poll gate uses — a browser
 * stops polling exactly here, so a test that stopped earlier (or later) would
 * be asserting against a state the real page never sits in.
 */
function tickUntilSettled(\Livewire\Features\SupportTesting\Testable $test, int $max = 10): void
{
    for ($i = 0; $i < $max; $i++) {
        $test->call('tick');

        if (! in_array($test->get('structuresStatus'), ['pending', 'loading'], true)
            && ! in_array($test->get('propertiesStatus'), ['pending', 'building'], true)
            && $test->get('enrichmentStatus') !== 'pending') {
            return;
        }
    }
}

it('sends only the matrikel-ids of property nodes actually in the graph to properties/batch', function () {
    // ONE company whose portfolio carries NINE properties, against a
    // properties_per_company cap of 6: three of the fetched rows live in
    // $propertyData without ever becoming nodes, and the batch must ask only
    // about the six that did.
    //
    // 🚨 The failure this pins: sending the union of every fetched portfolio
    // list. On a person with several property-heavy companies that is a batch
    // of hundreds of ids per enrichment pass, of which only the handful the
    // caps actually drew can ever be shown.
    $rows = collect(range(1, 9))->map(fn ($n) => personPortfolioRow('11111111', '900'.$n))->all();

    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => $rows],
        ['11111111' => ['financials' => []]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);
    $test->call('loadEnrichment');

    expect($test->get('enrichmentStatus'))->toBe('loaded');

    // The graph drew 6 of the 9 (properties_per_company cap).
    $drawn = collect($test->get('graphModel')['nodes'])
        ->where('kind', 'property')->pluck('id')
        ->map(fn ($id) => substr($id, 4))->sort()->values()->all();

    expect($drawn)->toHaveCount(6);

    Http::assertSent(function ($request) use ($drawn) {
        if (! str_contains($request->url(), '/properties/batch')) {
            return false;
        }

        $sent = collect($request->data()['matrikel_ids'] ?? [])->sort()->values()->all();

        return $sent === $drawn;
    });

    // And never the full portfolio list.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/properties/batch')
        && count($request->data()['matrikel_ids'] ?? []) > 6);
});

it('never sends the person root or a person node to the company-info pool', function () {
    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        [],
        ['11111111' => ['financials' => []]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);
    $test->call('loadEnrichment');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/cvr/company/11111111'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/cvr/company/person'));
    // The CPR must never be sent as if it were a cvr.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/cvr/company/0101011234'));
});

it('passes API-sourced financials through as hele kroner (no *1000) — shared F-A pin, person side', function () {
    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        [],
        ['11111111' => ['financials' => [
            ['year' => '2024', 'equity' => 92438600, 'assets' => 92901000, 'profit_loss' => 87545200],
        ]]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);
    $test->call('loadEnrichment');

    expect($test->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($test->get('graphModel')['nodes'])->firstWhere('id', '11111111');
    expect($node['card']['equity'])->toBe(92_438_600)
        ->and($node['card']['result'])->toBe(87_545_200);
});

it('converts pdf-sourced financials from t.DKK to hele kroner (*1000) — shared F-A pin, person side', function () {
    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        [],
        ['11111111' => ['financials' => [
            ['year' => '2024', 'equity' => 2527, 'assets' => 9000, 'profit_loss' => 316, 'source' => 'pdf'],
        ]]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);
    $test->call('loadEnrichment');

    $node = collect($test->get('graphModel')['nodes'])->firstWhere('id', '11111111');
    expect($node['card']['equity'])->toBe(2_527_000)
        ->and($node['card']['result'])->toBe(316_000);
});

it('gates enrichment on both progressive phases having settled', function () {
    // Five cvrs = two ticks of fase 2 before it settles, so an enrichment
    // attempt after ONE tick must be a no-op: the cvr set is still growing,
    // and pooling now would enrich companies the next tick may add siblings to
    // (and would settle enrichmentStatus='loaded', permanently blocking the
    // real pass behind its own idempotency gate).
    $companies = collect(range(1, 5))
        ->map(fn ($n) => cprOwnershipCompany(str_repeat((string) $n, 8), 100.0, "Holding {$n}"))
        ->all();

    fakePersonEnrichment($companies, [], [], collect($companies)
        ->mapWithKeys(fn ($c) => [$c['cvr'] => ['financials' => []]])->all());

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // 3 of 5 structures

    expect($test->get('structuresStatus'))->toBe('loading');

    $test->call('loadEnrichment');
    expect($test->get('enrichmentStatus'))->toBe('pending');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/cvr/company/11111111'));

    tickUntilSettled($test);
    $test->call('loadEnrichment');
    expect($test->get('enrichmentStatus'))->toBe('loaded');
});

it('gates enrichment on fase 3 too, not just fase 2', function () {
    // The fase-3 half of the gate above, pinned SEPARATELY because tick()
    // early-returns while property work is pending and therefore never
    // exercises it — mutation-tested: dropping propertiesSettled() from
    // loadEnrichment()'s gate left the whole suite green until this test
    // existed. loadEnrichment() is public (the Blade's retry calls it, and so
    // does any future trigger), so the gate has to hold on its own.
    //
    // Enriching mid-fase-3 would batch a property set that is still growing
    // AND settle 'loaded', permanently blocking the real pass behind the
    // idempotency gate — the property cards would never arrive.
    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => 'building'],
        ['11111111' => ['financials' => [['year' => '2024', 'equity' => 500_000, 'profit_loss' => 1]]]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick'); // fase 2 settles, fase 3 seeded
    $test->call('tick'); // fase 3 answers 'building' — still not settled

    expect($test->get('propertiesStatus'))->toBe('building');

    $test->call('loadEnrichment');

    expect($test->get('enrichmentStatus'))->toBe('pending');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/cvr/company/11111111'));
});

it('runs enrichment from the tick loop once phases 2 and 3 settle, without a separate trigger', function () {
    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => [personPortfolioRow('11111111', '2573669')]],
        ['11111111' => ['financials' => [['year' => '2024', 'equity' => 500_000, 'profit_loss' => 50_000]]]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    expect($test->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($test->get('graphModel')['nodes'])->firstWhere('id', '11111111');
    expect($node['card']['equity'])->toBe(500_000);
});

it('does not let a failed phase withhold enrichment', function () {
    // A structures failure is settled, not pending: the companies that DID
    // load still deserve their cards (spec regel 4's reasoning, one phase on).
    fakePersonEnrichment(
        [
            cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
            cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
        ],
        ['22222222' => null],
        [],
        [
            '11111111' => ['financials' => [['year' => '2024', 'equity' => 700_000, 'profit_loss' => 1]]],
            '22222222' => ['financials' => [['year' => '2024', 'equity' => 800_000, 'profit_loss' => 2]]],
        ],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    expect($test->get('structuresStatus'))->toBe('failed')
        ->and($test->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($test->get('graphModel')['nodes'])->firstWhere('id', '11111111');
    expect($node['card']['equity'])->toBe(700_000);
});

/**
 * A PER-CVR pool failure is NOT a phase failure. Verified against the live
 * client rather than assumed: fetchCompanyInfosPooled() maps an unreachable
 * cvr (a ConnectionException object in the pool result, or any non-2xx) to a
 * null entry and returns normally — it does not throw. So the company simply
 * gets no card, and the phase settles 'loaded' like any other.
 *
 * That distinction is the reason 'failed' is reachable ONLY via a total
 * failure (the pooled call itself throwing), which is what the next test
 * covers. Pinning it here stops a future "make enrichment stricter" change
 * from turning one unreachable company into a graph-wide error banner.
 */
it('treats a single unreachable company as a missing card, not a failed phase', function () {
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
            cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*/properties/batch*' => Http::response(['data' => []]),
        '*/v1/cvr/company/22222222*' => Http::response('Server error', 500),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
            'cvr' => '11111111',
            'financials' => [['year' => '2024', 'equity' => 111_000, 'profit_loss' => 1]],
        ]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    expect($test->get('enrichmentStatus'))->toBe('loaded');

    $nodes = collect($test->get('graphModel')['nodes']);
    expect($nodes->firstWhere('id', '11111111')['card']['equity'])->toBe(111_000)
        ->and($nodes->firstWhere('id', '22222222')['card'] ?? null)->toBeNull();
});

it('flips enrichment to failed on a total pool failure and lets retryEnrichment re-run it', function () {
    // A TOTAL failure — the pooled call itself throwing, rather than a cvr
    // coming back null — is the only path to 'failed' (see the test above).
    // Simulated by swapping in a RegistryApi whose pooled fetch throws once,
    // because no Http::fake can make Http::pool() itself blow up.
    $api = new class extends \TheFountainhead\Metis\Services\RegistryApi
    {
        public int $calls = 0;

        public function fetchCompanyInfosPooled(array $cvrs): array
        {
            $this->calls++;

            if ($this->calls === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('pool down');
            }

            return ['11111111' => [
                'cvr' => '11111111',
                'financials' => [['year' => '2024', 'equity' => 123_000, 'profit_loss' => 9_000]],
            ]];
        }
    };

    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        [],
        ['11111111' => ['financials' => []]],
    );

    app()->instance(\TheFountainhead\Metis\Services\RegistryApi::class, $api);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    expect($test->get('enrichmentStatus'))->toBe('failed');

    $test->call('retryEnrichment');

    expect($test->get('enrichmentStatus'))->toBe('loaded');
    $node = collect($test->get('graphModel')['nodes'])->firstWhere('id', '11111111');
    expect($node['card']['equity'])->toBe(123_000);
});

/*
|--------------------------------------------------------------------------
| Enrichment across a hydration boundary
|--------------------------------------------------------------------------
| $enrichmentData is PROTECTED, so it is gone on every subsequent request
| while enrichmentStatus still says 'loaded'. Recovery must be partial-
| tolerant and must not turn an interactive request (a chip toggle) into a
| synchronous fetch storm: every source it reads is a warm cache or a single
| cheap batch, and a piece it cannot recover is handed BACK to the poll loop
| rather than force-fetched here.
*/

it('reproduces the cards after a rehydrate + chip toggle without a fetch storm', function () {
    fakePersonEnrichment(
        [
            cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
            cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
        ],
        [],
        ['11111111' => [personPortfolioRow('11111111', '2573669')]],
        [
            '11111111' => ['financials' => [['year' => '2024', 'equity' => 500_000, 'profit_loss' => 50_000]]],
            '22222222' => ['financials' => [['year' => '2024', 'equity' => 300_000, 'profit_loss' => 30_000]]],
        ],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    expect($test->get('enrichmentStatus'))->toBe('loaded');

    $before = Http::recorded()->count();

    // A brand new instance carrying only PUBLIC state — every protected
    // builder input, enrichmentData included, is back at its default.
    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    $node = collect($fresh->graphModel['nodes'])->firstWhere('id', '11111111');
    expect($node['card']['equity'])->toBe(500_000);

    $prop = collect($fresh->graphModel['nodes'])->firstWhere('kind', 'property');
    expect($prop['card']['usage'] ?? null)->toBe('Bolig');

    // Every source the recovery reads is cached (24h company-info, 5min
    // portfolio/structure) except the single properties/batch call, which is
    // one request regardless of how many properties there are. A regression
    // that re-pools every company or re-fetches every portfolio uncached
    // would blow straight past this ceiling.
    $spent = Http::recorded()->count() - $before;
    expect($spent)->toBeLessThanOrEqual(2);
});

it('hands an unrecoverable enrichment piece back to the poll loop instead of fetching everything synchronously', function () {
    // The company-info cache is the recovery source for company cards. When
    // it has been evicted for ONE cvr, that cvr's enrichment cannot be
    // recovered cheaply — so the recovery must reset the phase to 'pending'
    // and let the poll loop re-run it, NOT re-pool every company inline in
    // what is an interactive (chip-toggle) request.
    fakePersonEnrichment(
        [
            cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
            cprOwnershipCompany('22222222', 100.0, 'Holding B ApS'),
        ],
        [],
        [],
        [
            '11111111' => ['financials' => [['year' => '2024', 'equity' => 111_000, 'profit_loss' => 1]]],
            '22222222' => ['financials' => [['year' => '2024', 'equity' => 222_000, 'profit_loss' => 2]]],
        ],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);
    expect($test->get('enrichmentStatus'))->toBe('loaded');

    // Evict ONE cvr's company-info cache — the other stays warm.
    \Illuminate\Support\Facades\Cache::forget('metis:company_info:22222222');

    $before = Http::recorded()->count();

    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect($fresh->enrichmentStatus)->toBe('pending');

    // One pooled miss for the evicted cvr is the ceiling — never a full
    // re-pool of both, and never a synchronous re-fetch of the whole phase.
    $spent = \Illuminate\Support\Facades\Http::recorded()->count() - $before;
    expect($spent)->toBeLessThanOrEqual(2);
});

it('keeps the graph rendered while a reset enrichment phase is re-run by the poll', function () {
    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        [],
        ['11111111' => ['financials' => [['year' => '2024', 'equity' => 500_000, 'profit_loss' => 1]]]],
    );

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    \Illuminate\Support\Facades\Cache::forget('metis:company_info:11111111');

    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    // Reset, not failed — and the company node is still there, just without
    // its card until the poll re-runs the phase.
    expect($fresh->enrichmentStatus)->toBe('pending')
        ->and(collect($fresh->graphModel['nodes'])->pluck('id'))->toContain('11111111');
});

/**
 * 🚨 The PersonStructure counterpart of 2a's F4 regression test, and the pin
 * for recoverEnrichmentResults()'s rebuild() call.
 *
 * WHY THE OTHER RECOVERY TESTS DO NOT COVER IT: rehydratedFrom() copies the
 * PUBLIC state across, and $graphModel is public — so the fresh instance
 * starts with a graph that ALREADY contains the property nodes, and the
 * graph-derived enrichment scope resolves correctly whether or not recovery
 * rebuilt first. Mutation-tested: deleting the rebuild() left all 56
 * PersonStructure tests green. The line was load-bearing production code with
 * zero coverage.
 *
 * WHAT THIS TEST DOES DIFFERENTLY: a genuine fresh mount, with the phase
 * statuses forced to their post-hydration values while $graphModel is left at
 * whatever mount produced — a skeleton with NO property nodes, because the
 * portfolios had not been fetched at mount time. That is the real shape of a
 * hydrated request: public state says 'loaded', the protected builder inputs
 * are gone, and the graph on hand predates them.
 *
 * Without the rebuild(), enrichmentMatrikelIds() reads that stale skeleton,
 * finds zero property nodes, sends an empty batch, and the property cards
 * silently never arrive — the exact failure the 2a suite caught during the
 * extraction, here on the person side where nothing was watching for it.
 */
it('rebuilds before resolving enrichment so recovery sees the just-recovered property nodes', function () {
    fakePersonEnrichment(
        [cprOwnershipCompany('11111111', 100.0, 'Holding ApS')],
        [],
        ['11111111' => [personPortfolioRow('11111111', '2573669')]],
        ['11111111' => ['financials' => [['year' => '2024', 'equity' => 500_000, 'profit_loss' => 50_000]]]],
    );

    // Warm the caches the recovery reads, the way a previous request would
    // have: one full run through every phase.
    tickUntilSettled(Livewire::test(PersonStructure::class, ['query' => '0101011234']));

    // A FRESH mount — not rehydratedFrom(), so $graphModel is the one mount
    // built (skeleton only, no property nodes) rather than a copy of the
    // finished graph. The statuses are forced to what a hydrated request
    // would carry.
    $second = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect(collect($second->get('graphModel')['nodes'])->where('kind', 'property'))->toBeEmpty();

    $second->set('structuresStatus', 'loaded')
        ->set('structureByCompany', ['11111111' => 'loaded'])
        ->set('propertiesStatus', 'loaded')
        ->set('propertiesByCompany', ['11111111' => 'loaded'])
        ->set('enrichmentStatus', 'loaded')
        ->call('toggleLayer', 'roles');

    $nodes = collect($second->get('graphModel')['nodes']);

    // The property node came back AND carries its batch-derived card. The
    // card is the load-bearing half: the node itself would return from the
    // fase-3 recovery regardless, but its usage can only be there if the
    // batch call was given the matrikel-id — which requires the rebuild.
    $prop = $nodes->firstWhere('kind', 'property');
    expect($prop)->not->toBeNull()
        ->and($prop['card']['usage'] ?? null)->toBe('Bolig');

    expect($nodes->firstWhere('id', '11111111')['card']['equity'] ?? null)->toBe(500_000);
});

/*
|--------------------------------------------------------------------------
| Dashed role edges — CSS variant (Task 1's host-JS counterpart)
|--------------------------------------------------------------------------
*/

it('ships the dashed edge CSS variant the role layer depends on', function () {
    // The builder emits style='dashed' on role edges (Task 4) and the host JS
    // maps that to `mgraph-edge-line mgraph-edge-dashed` (Task 1). Without the
    // rule in THIS package's partial the class is inert and role edges render
    // as solid — indistinguishable from ownership, which is the one visual
    // distinction the whole role layer rests on.
    $partial = file_get_contents(__DIR__.'/../../../../resources/views/livewire/sections/partials/ownership-graph.blade.php');

    expect($partial)->toContain('.mgraph-edge-dashed')
        ->and($partial)->toMatch('/\.mgraph-edge-dashed\s*\{[^}]*stroke-dasharray:\s*4 4/');
});

/*
|--------------------------------------------------------------------------
| CPR DOM hygiene — the RENDERED HTML, not just the graph payload
|--------------------------------------------------------------------------
*/

it('never renders the CPR into the section markup, in any DOM attribute', function () {
    // The pre-existing pin only checked graphModel — which is exactly how the
    // wire:key leak slipped through: `wire:key="ownership-graph-{{ $query }}"`
    // put the raw CPR straight into the markup while the payload stayed clean.
    // This asserts on the SHAPE of a CPR (any bare 10-digit run) rather than on
    // one attribute name, so the NEXT attribute that interpolates $query fails
    // here whatever it is called.
    //
    // 🚨 SCOPE, and it is a real limit, not an oversight: the wire:snapshot
    // attribute legitimately carries the CPR, because `public string $query`
    // lives on the base MetisSection and every section on the page has it. That
    // is a page-level fact (the URL carries the CPR too) and cannot be fixed
    // here — PHP cannot reduce an inherited property's visibility. So the
    // snapshot is excised before the assertion and what remains under test is
    // exactly what this PR owns: the graph surface's OWN attributes.
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 60.0, 'Lars Holding ApS'),
        cprRoleCompany('22222222', 'Bestyrelsesformand', 'Drift A/S'),
    ]);

    $html = Livewire::test(PersonStructure::class, ['query' => '0101011234'])->html();

    $markup = preg_replace('/wire:snapshot="[^"]*"/', '', $html);

    // Guard the guard: if Livewire ever renames the snapshot attribute, the
    // excision silently stops matching and the assertion below turns into a
    // tautology that passes over a real leak.
    expect($markup)->not->toBe($html)
        ->and($markup)->not->toContain('0101011234');

    // The cvrs in this fixture are 8 digits and sha1 is hex, so a bare 10-digit
    // run means real digits leaked. (?<!\d)…(?!\d) so an 11-digit id cannot
    // sneak past a naive \d{10}.
    expect($markup)->not->toMatch('/(?<!\d)\d{10}(?!\d)/');
});

it('keys the graph island and the poll host by a HASH of the query', function () {
    fakeRegistryCpr([cprOwnershipCompany('11111111')]);

    $html = Livewire::test(PersonStructure::class, ['query' => '0101011234'])->html();

    // Both keys present, both hashed. The poll host needs its own key at all
    // (julik P2): .metis-org-chart sits among keyed siblings, so morph
    // index-matching can swap it out and permanently kill wire:poll's interval.
    expect($html)->toContain('ownership-graph-'.sha1('0101011234'))
        ->and($html)->toContain('org-chart-'.sha1('0101011234'));
});

/*
|--------------------------------------------------------------------------
| Chip + expand affordances (julik P1/P3)
|--------------------------------------------------------------------------
*/

it('disables the layer chips only while a toggleLayer round-trip is in flight', function () {
    // wire:target is ESSENTIAL, not decoration: the section polls every 2s, so
    // an untargeted wire:loading would grey the chips out twice a second for
    // the whole life of the page — the control would look permanently broken.
    $blade = file_get_contents(__DIR__.'/../../../../resources/views/livewire/sections/person-structure.blade.php');

    expect($blade)->toContain('wire:loading.attr="disabled"')
        ->and($blade)->toContain('wire:target="toggleLayer"');
});

it('drives the expand button busy state from Livewire, never from surviving Alpine state', function () {
    // The x-for is keyed, so an x-data={busy} scope SURVIVES a rebuild: a node
    // that still has hidden children after an expand kept busy=true and showed
    // '…' forever. wire:loading is cleared by Livewire on every response, so it
    // cannot get stuck.
    $partial = file_get_contents(__DIR__.'/../../../../resources/views/livewire/sections/partials/graph-node.blade.php');

    expect($partial)->toContain('wire:loading.attr="disabled"')
        ->and($partial)->toContain('wire:target="expandNode"')
        // The x-data busy flag itself must be gone — matched as the DECLARATION
        // rather than the bare word, so the docblock explaining why it was
        // removed does not keep this test red forever.
        ->and($partial)->not->toMatch('/x-data\s*=\s*"\{\s*busy/');
});

it('does not refire cross-ownership on every poll tick', function () {
    // The call is made on mount and again from rehydrateBeforeRebuild(), which
    // every tick runs through. Before the cache each of those was a live POST.
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 60.0, 'Lars Holding ApS'),
        cprOwnershipCompany('22222222', 40.0, 'Anden Holding ApS'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $afterMount = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'cross-ownership'))->count();

    expect($afterMount)->toBe(1);

    $test->call('tick')->call('tick')->call('toggleLayer', 'roles');

    $afterTicks = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'cross-ownership'))->count();

    expect($afterTicks)->toBe(1);
});

it('keeps enrichment loaded across a cold-cache toggle for a person with no properties', function () {
    // The old completeness guard was `companies !== [] && properties !== []`,
    // which cannot be satisfied by a person who HAS no properties: the
    // properties half is legitimately empty, so the guard read "never
    // recovered" and reset the phase to 'pending' on EVERY cold-cache
    // interactive request. The cards vanished and reappeared on each chip
    // click, forever, with nothing on screen explaining it.
    //
    // The fix compares against the EXPECTED counts (enrichmentCvrs /
    // enrichmentMatrikelIds), so an empty expectation passes trivially.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Lars Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        // No properties at all — the whole point of this fixture.
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
            'financials' => [['year' => 2024, 'equity' => 500_000, 'profit_loss' => 50_000, 'source' => 'api']],
        ]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick')->call('tick')->call('tick');

    expect($test->get('enrichmentStatus'))->toBe('loaded')
        ->and($test->get('propertiesStatus'))->toBe('empty');

    // 🚨 What the count-based guard actually fixes, probed rather than assumed:
    // NOT the early return (enrichmentData is protected, so a fresh instance
    // always starts empty and the guard falls through for anyone with
    // companies — under BOTH the old and the new test). What it fixes is the
    // OUTCOME once the recovery pass has run.
    //
    // fetchEnrichmentDataFromCache() recovers the one company and returns
    // complete=true, so the phase survives here. The bug the old guard caused
    // is one layer down and is pinned in the sibling test below: with the
    // properties half legitimately empty, "recovered" and "not recovered" were
    // indistinguishable to `properties !== []`, so a person with no properties
    // could never be judged complete on the strength of their own data.
    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect($fresh->enrichmentStatus)->toBe('loaded');
});

it('judges a no-properties person complete on companies alone, not on an empty properties half', function () {
    // The behavioural core of the guard fix, isolated from the recovery pass:
    // an enrichmentData whose companies half is fully populated and whose
    // properties half is empty BECAUSE THE GRAPH HAS NO PROPERTY NODES is
    // complete. `properties !== []` called that incomplete and re-ran the whole
    // pass — on every interactive request, forever, since no amount of
    // recovering can make an empty expectation non-empty.
    fakeRegistryCpr([cprOwnershipCompany('11111111', 100.0, 'Lars Holding ApS')]);

    $probe = new class extends PersonStructure
    {
        public int $recoveryAttempts = 0;

        public function seed(array $companies): void
        {
            $this->enrichmentData = ['companies' => $companies, 'properties' => []];
        }

        public function runRecovery(): void
        {
            $this->recoverEnrichmentResults();
        }

        protected function fetchEnrichmentDataFromCache(): bool
        {
            $this->recoveryAttempts++;

            return parent::fetchEnrichmentDataFromCache();
        }
    };

    $probe->query = '0101011234';
    $probe->skeletonStatus = 'loaded';
    $probe->enrichmentStatus = 'loaded';
    $probe->graphModel = ['nodes' => [
        ['id' => 'person:root', 'kind' => 'person', 'cvr' => null],
        ['id' => '11111111', 'kind' => 'legal', 'cvr' => '11111111'],
    ], 'edges' => []];
    $probe->seed(['11111111' => ['equity' => 500_000]]);

    $probe->runRecovery();

    // Complete ⇒ early return: no recovery pass, phase untouched.
    expect($probe->recoveryAttempts)->toBe(0)
        ->and($probe->enrichmentStatus)->toBe('loaded');
});

it('hands enrichment back when the company-info cache is genuinely cold', function () {
    // The counterpart to the no-properties pin above: the completeness guard
    // must still FIRE when something real is missing. Without this the fix
    // could be "always return early", which would strand a person whose cards
    // never arrive.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Lars Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
            'financials' => [['year' => 2024, 'equity' => 500_000, 'profit_loss' => 50_000, 'source' => 'api']],
        ]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick')->call('tick')->call('tick');

    expect($test->get('enrichmentStatus'))->toBe('loaded');

    // Evict the 24h company-info entry — now the ONE expected cvr cannot be
    // recovered, so the phase must go back to the poll loop rather than render
    // a graph whose cards silently never appear.
    \Illuminate\Support\Facades\Cache::flush();

    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect($fresh->enrichmentStatus)->toBe('pending');
});

/*
|--------------------------------------------------------------------------
| Poll failures are not interactive failures (julik P2)
|--------------------------------------------------------------------------
*/

it('retries silently when a BACKGROUND poll hits a transient rehydration failure', function () {
    // A tick is not a user action. Surfacing the staleData note for one failed
    // poll offers a "Prøv igen" that calls retrySkeleton() — which resets every
    // downstream phase and throws away minutes of accumulated structure and
    // property loading. The next tick is 2s away and normally succeeds, so the
    // honest response to a transient poll failure is to say nothing and retry.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::sequence()
            ->push(['data' => ['companies' => [
                cprOwnershipCompany('11111111', 100.0, 'Holding ApS'),
                cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
            ]]])
            ->push('Server error', 500),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $before = $test->get('graphModel');

    // Flush, or the recovery is served by fetchCompaniesByCprCached's 5-min
    // entry and the sequence's 500 is never reached — the test would assert
    // against a SUCCESSFUL rehydration and prove nothing. (Probed: without
    // this the refetch returns the cached companies list.)
    \Illuminate\Support\Facades\Cache::flush();

    $fresh = rehydratedFrom($test);
    $fresh->tick();

    // Last-good graph kept (identical to the interactive path) but NO note:
    // the work already loaded stays reachable and the poll simply comes round
    // again. toEqual, not toBe — getData()'s JSON round-trip coerces 100.0.
    expect($fresh->graphModel)->toEqual($before)
        ->and($fresh->staleData)->toBeFalse();
});

it('still surfaces the stale note when an INTERACTIVE action hits the same failure', function () {
    // Same failure, same recovery, different contract: the user asked for
    // something and did not get it, so silence would read as a broken control.
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

    // Same reason as the background test above: without the flush the cached
    // companies list satisfies the refetch and no failure occurs at all.
    \Illuminate\Support\Facades\Cache::flush();

    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect($fresh->staleData)->toBeTrue();
});

it('drops a card website whose scheme is not http(s)', function () {
    // card.website flows straight into `:href` on the hover card. registry-api's
    // contact.website is external, unvalidated data — a `javascript:` value
    // there becomes a script-execution sink the moment a user clicks the link.
    // Anything that is not http/https is dropped entirely rather than rendered.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Lars Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
            'contact' => ['website' => 'javascript:alert(document.cookie)'],
            'financials' => [['year' => 2024, 'equity' => 1, 'profit_loss' => 1, 'source' => 'api']],
        ]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick')->call('tick')->call('tick');

    expect($test->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($test->get('graphModel')['nodes'])->firstWhere('cvr', '11111111');

    expect($node['card']['website'] ?? null)->toBeNull()
        ->and(json_encode($test->get('graphModel')))->not->toContain('javascript:');
});

it('keeps an ordinary https website on the card', function () {
    // The counterpart: the guard must not throw away legitimate links.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Lars Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
            'contact' => ['website' => 'https://example.dk'],
            'financials' => [['year' => 2024, 'equity' => 1, 'profit_loss' => 1, 'source' => 'api']],
        ]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick')->call('tick')->call('tick');

    $node = collect($test->get('graphModel')['nodes'])->firstWhere('cvr', '11111111');

    expect($node['card']['website'] ?? null)->toBe('https://example.dk');
});

it('keeps a SCHEME-LESS website, because that is what registry-api mostly returns', function () {
    // Bare domains ("kirketorvet.dk") are the common shape in CVR data, so
    // rejecting a missing scheme would silently delete most real websites —
    // a functional regression dressed up as hardening. It is safe exactly
    // because it has no scheme: with no ':' before the first '/' the value
    // cannot express javascript:, and the browser resolves it as relative.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [
            cprOwnershipCompany('11111111', 100.0, 'Lars Holding ApS'),
        ]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
            'contact' => ['website' => 'kirketorvet.dk'],
            'financials' => [['year' => 2024, 'equity' => 1, 'profit_loss' => 1, 'source' => 'api']],
        ]]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick')->call('tick')->call('tick');

    $node = collect($test->get('graphModel')['nodes'])->firstWhere('cvr', '11111111');

    expect($node['card']['website'] ?? null)->toBe('kirketorvet.dk');
});

it('gates the card streetview image on the svOk metadata flag in the shared partial', function () {
    // Host-JS' metadata-gate sætter svOk; uden gaten viste kortet Googles grå
    // "no imagery"-placeholder (200 OK — :src/onerror kan ikke skelne), og
    // uden :src-guarden ville placeholder-billedet stadig blive DOWNLOADET.
    $partial = file_get_contents(__DIR__.'/../../../../resources/views/livewire/sections/partials/ownership-graph.blade.php');

    expect($partial)->toContain('card.node.card?.streetview_url && card.svOk')
        ->and($partial)->toContain("card.svOk ? (card.node.card?.streetview_url ?? '') : ''");
});

/*
|--------------------------------------------------------------------------
| Private ejendomme — the THIRD layer (Task 5)
|--------------------------------------------------------------------------
| The person's OWN property portfolio, fetched once from
| /v1/person/property-portfolio and hung on person:root as 'pp:'-nodes. It is
| a phase of its own: independent of the cvr queues (nothing about it waits on
| a company structure), so tick() runs it in its own branch BEFORE fase 2 and
| then FALLS THROUGH — the one deliberate exception to the
| one-thing-per-tick discipline, and the reason the branch is documented
| rather than merely written (spec P1-5c).
*/

/**
 * Fase-1..3 fake PLUS the person's own portfolio endpoint.
 *
 * 🚨 The person pattern must be registered BEFORE the generic
 * property-portfolio wildcard: Http::fake matches in insertion order and that
 * pattern also matches
 * '/v1/person/property-portfolio' (it is a suffix of the URL). Registered the
 * other way round the company handler would answer the person call with a
 * ['portfolio' => …] payload and every private-properties test would see
 * 'empty' — the fake, not the component, deciding the outcome.
 *
 * $private: a list of personal_properties rows, 'missing' for a 200 whose body
 * carries no personal_properties key at all, or null for a hard 500.
 */
function fakePersonPrivate(array $companies, array|string|null $private = [], array $structures = [], array $portfolios = [], array $relationships = []): void
{
    Http::fake([
        '*/v1/person/property-portfolio*' => match (true) {
            $private === null => Http::response('Server error', 500),
            $private === 'missing' => Http::response(['data' => ['summary' => []]]),
            default => Http::response(['data' => ['personal_properties' => $private]]),
        },
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => $companies]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => $relationships]]),
        '*/v1/cvr/company-structure*' => function ($request) use ($structures) {
            $cvr = $request->data()['cvr'] ?? null;
            $payload = array_key_exists($cvr, $structures) ? $structures[$cvr] : ['subsidiaries' => []];

            return $payload === null
                ? Http::response('Server error', 500)
                : Http::response(['data' => $payload]);
        },
        '*/property-portfolio*' => function ($request) use ($portfolios) {
            preg_match('#/company/(\d+)/property-portfolio#', $request->url(), $m);
            $cvr = $m[1] ?? '';
            $rows = array_key_exists($cvr, $portfolios) ? $portfolios[$cvr] : [];

            return Http::response(['data' => ['portfolio' => [
                'properties' => $rows,
                'property_count' => count($rows),
                'total_count' => count($rows),
            ]]]);
        },
        '*/properties/batch*' => Http::response(['data' => []]),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => []]]),
    ]);
}

/** One personal_properties row, in the shape the endpoint returns. */
function privatePropertyRow(string $matrikel = '1a Testby', string $address = 'Travervænget 3'): array
{
    return [
        'matrikelnummer' => $matrikel,
        'address' => $address,
        'city' => 'Testby',
        'zip' => '8000',
        'public_valuation' => 2_450_000,
        'area_building' => 142,
        'year_built' => 1974,
        'ownership_share' => 50.0,
        'co_owners' => [['name' => 'Medejer']],
        'mortgages' => [],
    ];
}

it('preselects the private-properties layer and fetches the portfolio on the first tick', function () {
    fakePersonPrivate([cprOwnershipCompany('11111111')], [privatePropertyRow()]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    // Preselected, and pending until the poll runs — mount does fase 1 only.
    expect($test->get('layers'))->toContain('private_properties')
        ->and($test->get('privatePropertiesStatus'))->toBe('pending');

    $test->call('tick');

    expect($test->get('privatePropertiesStatus'))->toBe('loaded')
        ->and($test->get('privatePropertiesCount'))->toBe(1);

    $pp = collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:'));
    expect($pp)->toHaveCount(1)
        ->and($pp->first()['label'])->toBe('Travervænget 3');

    // Hung on the person root, and the CPR never reaches the payload.
    expect(collect($test->get('graphModel')['edges'])->pluck('from'))->toContain('person:root')
        ->and(json_encode($test->get('graphModel')))->not->toContain('0101011234');
});

it('runs the private-properties branch BEFORE fase 2 and then falls through in the same tick', function () {
    // The whole point of the fall-through (spec P1-5c): the call is independent
    // of the cvr queues, so making fase 2 wait a whole poll interval for it
    // would slow the graph down for nothing. One tick must do both.
    fakePersonPrivate([cprOwnershipCompany('11111111')], [privatePropertyRow()]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect($test->get('structureByCompany'))->toBe(['11111111' => 'pending']);

    $test->call('tick');

    expect($test->get('privatePropertiesStatus'))->toBe('loaded')
        ->and($test->get('structureByCompany'))->toBe(['11111111' => 'loaded']);
});

it('treats a successful response with no personal properties as empty, not failed', function () {
    fakePersonPrivate([cprOwnershipCompany('11111111')], 'missing');

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    expect($test->get('privatePropertiesStatus'))->toBe('empty')
        ->and($test->get('privatePropertiesCount'))->toBe(0);

    // 'empty' is a settled answer, so it says nothing on screen (2a's rule).
    $test->assertDontSee('Private ejendomme kunne ikke hentes');
});

it('marks the private-properties phase failed on a hard failure, zeroes the badge and offers its own retry', function () {
    // null ≠ tom, and P2-4: a failed phase sets the count to 0 so the chip is
    // treated as an empty layer (freely deselectable) while the badge shows
    // "(–)" — a dash, never a 0 that would read as a fact about the person.
    // Sequenced load-then-FAIL rather than fail-then-load, deliberately: the
    // zeroing is only observable when the count was non-zero first. Probed as
    // fail-first, the assertion passed against a count that had simply never
    // moved off its declared 0 — mutation-testing the zeroing away left it green.
    Http::fake([
        '*/v1/person/property-portfolio*' => Http::sequence()
            ->push(['data' => ['personal_properties' => [privatePropertyRow('1a', 'A 1'), privatePropertyRow('2a', 'B 2'), privatePropertyRow('3a', 'C 3')]]])
            ->push('Server error', 500)
            ->push(['data' => ['personal_properties' => [privatePropertyRow()]]]),
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [cprOwnershipCompany('11111111')]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*/properties/batch*' => Http::response(['data' => []]),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => []]]),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    expect($test->get('privatePropertiesStatus'))->toBe('loaded')
        ->and($test->get('privatePropertiesCount'))->toBe(3);

    // The retry hits the 500. The count must be ZEROED (spec P2-4), not left at
    // 3: a stale count makes the chip behave as a NON-empty layer, and the
    // never-empty rule would then be able to lock a chip whose layer draws
    // nothing at all — an undismissable chip over an invisible layer.
    \Illuminate\Support\Facades\Cache::flush();
    $test->call('retryPrivateProperties');

    expect($test->get('privatePropertiesStatus'))->toBe('failed')
        ->and($test->get('privatePropertiesCount'))->toBe(0);

    $test->assertSee('Private ejendomme kunne ikke hentes')
        ->assertSee('Private ejendomme (–)');

    // And the retry is its OWN: the fase-3 budget must be untouched (that retry
    // resets the shared MAX_PROPERTIES_ATTEMPTS counter and re-opens settled cvrs).
    \Illuminate\Support\Facades\Cache::flush();
    $test->set('propertiesAttempts', 7)->call('retryPrivateProperties');

    expect($test->get('privatePropertiesStatus'))->toBe('loaded')
        ->and($test->get('privatePropertiesCount'))->toBe(1)
        ->and($test->get('propertiesAttempts'))->toBe(7);
});

it('fetches the private portfolio exactly once per page, not once per tick', function () {
    fakePersonPrivate([cprOwnershipCompany('11111111')], [privatePropertyRow()]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    tickUntilSettled($test);

    // Cached (5 min) AND status-gated: the gate is what this pins — a cache hit
    // would hide an ungated branch that re-reads on every one of the ~4 ticks.
    expect($test->get('privatePropertiesStatus'))->toBe('loaded');
    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/v1/person/property-portfolio')))
        ->toHaveCount(1);
});

it('shows the private-properties chip with a pre-cap badge and filters the layer off', function () {
    $rows = collect(range(1, 14))->map(fn ($i) => privatePropertyRow("{$i}a Testby", "Vej {$i}"))->all();
    fakePersonPrivate([cprOwnershipCompany('11111111')], $rows);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    // PRE-cap: 14, not the 10 person_private_properties actually drawn.
    expect($test->get('privatePropertiesCount'))->toBe(14);
    $test->assertSee('Private ejendomme (14)');

    expect(collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(10);

    $test->call('toggleLayer', 'private_properties');

    expect($test->get('layers'))->not->toContain('private_properties')
        ->and(collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(0);
});

it('refuses to switch off the private-properties chip when it carries the only nodes', function () {
    // The never-empty rule (count(nodes) <= 1) needs no third-layer special
    // case — this pins that it genuinely covers the new layer. Two routes
    // reach the only-private state: a person with NO active companies (the
    // promotion path — its own test above) and a person WITH a company whose
    // other chips have been switched off first, which is what this walks.
    fakePersonPrivate([cprRoleCompany('22222222')], [privatePropertyRow()]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    $test->call('toggleLayer', 'roles');
    expect($test->get('layers'))->toBe(['ownership', 'private_properties']);

    // Private properties now carry every visible node — the toggle is refused
    // outright, state untouched.
    $test->call('toggleLayer', 'private_properties');
    expect($test->get('layers'))->toBe(['ownership', 'private_properties'])
        ->and(collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(1);
});

it('recovers the private rows from cache across a hydration boundary without a network call', function () {
    fakePersonPrivate([cprOwnershipCompany('11111111')], [privatePropertyRow()]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');
    expect($test->get('privatePropertiesStatus'))->toBe('loaded');

    // A genuinely fresh instance: $privatePropertiesData is PROTECTED, so it is
    // gone while privatePropertiesStatus still says 'loaded'. The cache is warm,
    // so recovery reclaims the rows and the pp:-nodes survive the rebuild.
    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect($fresh->privatePropertiesStatus)->toBe('loaded')
        ->and(collect($fresh->graphModel['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(1);
});

it('hands the private phase back to the poll on a cache miss instead of fetching inside the click', function () {
    // 🚨 CACHE-ONLY (spec P1-5a). fetchPersonPropertyPortfolioByCprCached FALLS
    // THROUGH to a real POST on a miss, so recovery must not use it: a chip
    // toggle would then pay a 5-15s person-portfolio round-trip.
    fakePersonPrivate([cprOwnershipCompany('11111111')], [privatePropertyRow()]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    \Illuminate\Support\Facades\Cache::flush();
    Http::fake([
        '*/v1/person/property-portfolio*' => Http::response(['data' => ['personal_properties' => [privatePropertyRow()]]]),
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [cprOwnershipCompany('11111111')]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*/properties/batch*' => Http::response(['data' => []]),
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => []]]),
    ]);

    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    // Reset, not failed — the poll owns fetching.
    expect($fresh->privatePropertiesStatus)->toBe('pending');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/person/property-portfolio'));
});

/**
 * 🚨 The FRESH-MOUNT half of the recovery contract, and the T8 lesson applied:
 * rehydratedFrom() copies the PUBLIC state across, and $graphModel is public —
 * so the test above starts with a graph that ALREADY contains the pp:-nodes and
 * cannot observe their ABSENCE. Deleting the recovery call entirely would leave
 * it green.
 *
 * Here $graphModel is whatever a fresh mount produced (skeleton only, no
 * pp:-nodes, because the portfolio had not been fetched at mount time) while
 * the status is forced to the 'loaded' a hydrated request would carry. That is
 * the real shape of a second request, and the only shape in which a missing
 * recovery is visible.
 */
it('rebuilds the pp:-nodes from cache on a fresh mount whose graph predates them', function () {
    fakePersonPrivate([cprOwnershipCompany('11111111')], [privatePropertyRow()]);

    // A previous request ran the phase and warmed the 5-min cache.
    tickUntilSettled(Livewire::test(PersonStructure::class, ['query' => '0101011234']));

    $second = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    expect(collect($second->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toBeEmpty();

    $second->set('privatePropertiesStatus', 'loaded')
        ->set('privatePropertiesCount', 1)
        ->call('toggleLayer', 'roles');

    expect($second->get('privatePropertiesStatus'))->toBe('loaded')
        ->and(collect($second->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(1);
});

it('keeps polling while the private phase is pending, even with every other phase settled', function () {
    // The poll-gate is the ONLY thing that gets the phase run at all (mount
    // does fase 1 only). Ungated on privatePropertiesStatus, a person whose
    // companies all settle in the first tick would have the browser stop
    // polling before the private branch ever ran.
    fakePersonPrivate([cprOwnershipCompany('11111111')], [privatePropertyRow()]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    $test->set('structuresStatus', 'loaded')
        ->set('structureByCompany', ['11111111' => 'loaded'])
        ->set('propertiesStatus', 'loaded')
        ->set('propertiesByCompany', ['11111111' => 'loaded'])
        ->set('enrichmentStatus', 'loaded')
        ->set('privatePropertiesStatus', 'pending');

    expect($test->html())->toContain('wire:poll.2s="tick"');

    // …and stops once it settles.
    $test->set('privatePropertiesStatus', 'loaded');
    expect($test->html())->not->toContain('wire:poll.2s="tick"');
});

it('sends props:person:root from the property-expand button on the person root', function () {
    // 🚨 spec P1-2. The button emitted 'props:' + node.cvr, and the person root
    // has cvr=null → 'props:null': a permanently dead button, the only way to
    // reveal private properties past the cap. The partial is fixed to
    // (node.cvr ?? node.id), mirroring the relations button.
    $partial = file_get_contents(__DIR__.'/../../../../resources/views/livewire/sections/partials/graph-node.blade.php');

    expect($partial)->toContain("expandNode('props:' + (node.cvr ?? node.id))")
        ->and($partial)->not->toContain("expandNode('props:' + node.cvr)");

    // And the id the fixed button emits genuinely lifts the cap on the server.
    $rows = collect(range(1, 14))->map(fn ($i) => privatePropertyRow("{$i}a Testby", "Vej {$i}"))->all();
    fakePersonPrivate([cprOwnershipCompany('11111111')], $rows);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    $root = collect($test->get('graphModel')['nodes'])->firstWhere('id', 'person:root');
    expect($root['expand']['properties'] ?? 0)->toBe(4);

    $test->call('expandNode', 'props:person:root');

    expect($test->get('expandedNodeIds'))->toContain('props:person:root')
        ->and(collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(14);
});

/**
 * buildForPerson()'s privatePropertyId() is int-typed on the row index, so a
 * STRING-keyed map throws a TypeError rather than degrading. Verified, not
 * assumed: a GAP-keyed map (3 => …, 7 => …) does NOT throw — integer keys
 * satisfy the type whatever their values — so only string keys are dangerous,
 * and that is precisely the shape array_values() has to absorb.
 *
 * The endpoint itself cannot produce one (a JSON array always decodes as a
 * list), but the CACHE can: fetchPersonPropertyPortfolioByCprCached stores
 * whatever it is handed, so any pass that ever keys rows by matrikelnummer —
 * a natural thing to do to dedupe them — plants a string-keyed payload that the
 * recovery path then reads back verbatim.
 *
 * Probed WITHOUT the normalisation: TypeError out of the builder, 500, no graph.
 */
it('normalises a string-keyed cached portfolio into a list before it reaches the builder', function () {
    fakePersonPrivate([cprOwnershipCompany('11111111')], [privatePropertyRow('1a', 'A-vej 1'), privatePropertyRow('2a', 'B-vej 2')]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    expect(collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(2);

    // A matrikel-keyed cache entry — a map, not a list.
    \Illuminate\Support\Facades\Cache::put(
        'metis:person_property_portfolio:'.sha1('0101011234'),
        ['personal_properties' => ['1a' => privatePropertyRow('1a', 'A-vej 1'), '2a' => privatePropertyRow('2a', 'B-vej 2')]],
        300,
    );

    $fresh = rehydratedFrom($test);
    $fresh->toggleLayer('roles');

    expect($fresh->privatePropertiesStatus)->toBe('loaded')
        ->and(collect($fresh->graphModel['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:')))
        ->toHaveCount(2);
});

it('survives a string-keyed cached portfolio on the FETCH path — planted BEFORE the first tick', function () {
    // Re-review C5: fetchPersonPropertyPortfolioByCprCached returnerer cache-
    // værdien verbatim ved hit; en map her nåede privatePropertyId()'s int-
    // typede rækkeindeks som string → TypeError/500. De eksisterende map-tests
    // plantede cachen EFTER tick() og ramte kun recovery-stien.
    Cache::put('metis:person_property_portfolio:'.sha1('0101011234'), [
        'personal_properties' => ['1234a' => [
            'matrikelnummer' => '1234a', 'address' => 'Testvej 1', 'city' => 'X', 'zip' => '1000',
            'public_valuation' => 1000000, 'area_building' => 100, 'year_built' => 1980,
            'ownership_share' => 50, 'co_owners' => [], 'mortgages' => [],
        ]],
        'summary' => ['personal_property_count' => 1],
    ], 300);
    fakeRegistryCpr([cprOwnershipCompany('11111111', 100.0, 'Holding')]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);
    $test->call('tick');

    expect($test->get('privatePropertiesStatus'))->toBe('loaded')
        ->and(collect($test->get('graphModel')['nodes'])->filter(fn ($n) => str_starts_with($n['id'], 'pp:'))->count())->toBe(1);
});
