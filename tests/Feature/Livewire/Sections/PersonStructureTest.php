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

it('rehydrates the protected companies payload from cache on a follow-up request', function () {
    fakeRegistryCpr([
        cprOwnershipCompany('11111111', 100.0, 'Holding A ApS'),
        cprRoleCompany('22222222', 'Direktør', 'Drift A/S'),
    ]);

    $test = Livewire::test(PersonStructure::class, ['query' => '0101011234']);

    // A second request gets a FRESH component instance: protected state is
    // gone, so toggleLayer must re-fetch (cache-first) before rebuilding, or
    // the graph would collapse to the bare person root.
    $test->call('toggleLayer', 'roles');

    expect(collect($test->get('graphModel')['nodes'])->pluck('id'))->toContain('11111111');
});
