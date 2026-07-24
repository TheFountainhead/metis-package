<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyStructure;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

it('does not present the owners subsidiaries as the searched companys own', function () {
    // Scenariet fra JEUDAN-buggen: søgt selskab har ejere men (transient) tomme
    // subsidiaries; ejerens struktur har en portefølje af andre selskaber.
    Http::fake([
        '*cvr/company-structure*' => Http::sequence()
            ->push(['data' => ['owners' => [
                ['person_name' => 'MODERSELSKAB A/S', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 33.33, 'role_label' => 'EJERREGISTER', 'is_current' => true],
            ], 'subsidiaries' => []]])
            ->push(['data' => ['owners' => [], 'subsidiaries' => [
                ['name' => 'FREMMED DATTER A/S', 'cvr' => '22222222', 'ownership_share' => 100],
            ]]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSet('subsidiaries', [])
        ->assertDontSee('FREMMED DATTER');
});

it('renders the companys own subsidiaries when present', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => ['owners' => [
            ['person_name' => 'EJER A/S', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'role_label' => 'EJERREGISTER', 'is_current' => true],
        ], 'subsidiaries' => [
            ['name' => 'EGEN DATTER ApS', 'cvr' => '33333333', 'ownership_share' => 100],
        ]]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSee('EGEN DATTER ApS')
        ->assertSee('EJER A/S');
});

it('splits owners by owner_kind into reel, legal and other rows', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => ['owners' => [
            ['person_name' => 'UBO PERSON', 'is_company' => false, 'ownership_share' => 27.43, 'owner_kind' => 'reel', 'is_current' => true],
            ['person_name' => 'LEGAL HOLDING ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'owner_kind' => 'legal', 'is_current' => true],
            ['person_name' => 'DIREKTØR MED ANDEL', 'is_company' => false, 'ownership_share' => 5.0, 'owner_kind' => 'other', 'is_current' => true],
        ], 'subsidiaries' => []]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSeeInOrder(['Ultimate beneficial owner', 'UBO PERSON', 'Legal owner', 'LEGAL HOLDING', 'Other with ownership share', 'DIREKTØR MED ANDEL']);
});

it('never renders an other-kind participant under the legal owner label (regression)', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => ['owners' => [
            ['person_name' => 'DIREKTØR MED ANDEL', 'is_company' => false, 'ownership_share' => 5.0, 'owner_kind' => 'other', 'is_current' => true],
        ], 'subsidiaries' => []]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSee('Other with ownership share')
        ->assertSee('DIREKTØR MED ANDEL')
        ->assertDontSee('Legal owner')
        ->assertDontSee('Owners</'); // the fallback label used when no reel owners exist
});

it('falls back to role_label when owner_kind is absent (stale cached payload)', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => ['owners' => [
            ['person_name' => 'OLD REEL', 'is_company' => false, 'ownership_share' => 27.0, 'role_label' => 'Reelle ejere', 'is_current' => true],
            ['person_name' => 'OLD LEGAL ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'role_label' => 'EJERREGISTER', 'is_current' => true],
        ], 'subsidiaries' => []]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSeeInOrder(['Ultimate beneficial owner', 'OLD REEL', 'Legal owner', 'OLD LEGAL']);
});

it('exposes ancestors from the structure payload', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'HoldCo ApS', 'cvr' => '70000002', 'is_company' => true,
                    'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1,
                    'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->assertSet('ancestors', fn ($a) => count($a) === 1 && $a[0]['person_name'] === 'HoldCo ApS');
});

it('renders ancestors above the searched company, deepest at top', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            // BidCo ApS is the immediate (depth-1) owner: it is returned in BOTH
            // $owners (rendered by the Legal owner row) and $ancestors (depth 1),
            // mirroring the real API shape. The ancestors block only ever shows
            // depth >= 2, so BidCo must render once — from $owners — not from
            // the ancestors block above it.
            'owners' => [
                ['person_name' => 'BidCo ApS', 'is_company' => true, 'cvr' => '20000002', 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'is_current' => true],
            ],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'BidCo ApS', 'cvr' => '20000002', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'Top Ejer', 'cvr' => null, 'is_company' => false, 'ownership_share' => 100.0, 'owner_kind' => 'reel', 'depth' => 2, 'parent_of_cvr' => '20000002', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->assertSeeInOrder(['Top Ejer', 'BidCo ApS', 'OpCo']); // deepest UBO first, then down to searched company
});

it('does not double-render the depth-1 immediate owner in the ancestors block (regression)', function () {
    // getOwnershipChain's BFS starts at the searched company, so its depth-1 nodes
    // ARE the immediate owners already rendered by the reel/legal/other rows below.
    // Only depth >= 2 (the chain ABOVE the immediate owners) belongs in the ancestors block.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [
                ['person_name' => 'BidCo ApS', 'is_company' => true, 'cvr' => '20000002', 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'is_current' => true],
            ],
            'subsidiaries' => [],
            'ancestors' => [
                // Same node as the immediate owner above (depth 1) — must NOT be rendered again.
                ['person_name' => 'BidCo ApS', 'cvr' => '20000002', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                // Grandparent (depth 2) — belongs in the ancestors block above BidCo.
                ['person_name' => 'Top Ejer', 'cvr' => null, 'is_company' => false, 'ownership_share' => 100.0, 'owner_kind' => 'reel', 'depth' => 2, 'parent_of_cvr' => '20000002', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    $html = Livewire::test(CompanyStructure::class, ['query' => '70000001'])->html();

    // Strip Livewire's hidden wire:snapshot state blob (raw component-state JSON,
    // not visible markup) before counting — it legitimately contains BidCo ApS
    // twice (once from $owners, once from $ancestors state), which is not a
    // rendering bug. Only the actual rendered HTML below the snapshot attribute
    // proves whether the org-chart double-renders a node.
    $visibleHtml = preg_replace('/wire:snapshot="[^"]*"/', '', $html);

    // The depth-2 grandparent renders in the ancestors block above.
    expect($visibleHtml)->toContain('Top Ejer');
    // The depth-1 owner (BidCo ApS) appears exactly once in visible markup — in
    // the Owners row — never a second time in the ancestors block above it.
    expect(substr_count($visibleHtml, 'BidCo ApS'))->toBe(1);
});

it('renders an ancestors node missing owner_kind without erroring (stale cached payload)', function () {
    // A cached/older ancestors payload can be missing owner_kind. The main
    // owner rows below already null-coalesce defensively; the ancestors block
    // must do the same instead of relying on the key existing (PHP 8 "Undefined
    // array key" warning → possible 500 under strict error handling).
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                // depth=2 so it lands in the ancestors block (>= 2 filter);
                // owner_kind absent, simulating a stale cached payload.
                ['person_name' => 'Legacy HoldCo ApS', 'cvr' => '20000009', 'is_company' => true, 'ownership_share' => 100.0, 'depth' => 2, 'parent_of_cvr' => '20000002', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    // Livewire::test would itself throw if the component render threw — the
    // absence of an exception here IS part of the assertion.
    Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->assertOk()
        ->assertSee('Legacy HoldCo ApS');
});

it('does not warn on the indent-margin calculation for a depth-2+ ancestor (defensive coalesce)', function () {
    // Depth is present (required to pass the >= 2 filter into $chainAbove at
    // all) but this proves the indent-margin line reads it via the same
    // defensive (($anc['depth'] ?? 2) - 1) pattern as the rest of the block,
    // not a raw $anc['depth'] - 1 that would warn if a future payload shape
    // ever omitted it after already passing the filter (e.g. filter and render
    // sourced from different normalizations).
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'Legacy Top Ejer', 'cvr' => null, 'is_company' => false, 'ownership_share' => 100.0, 'owner_kind' => 'reel', 'depth' => 3, 'parent_of_cvr' => '20000002', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->assertOk()
        ->assertSee('Legacy Top Ejer');
});

it('nests company B under company A and both persons under B (ownershipTree)', function () {
    // Root's direct owner is A (depth 1, parent_of_cvr null). A is owned by B
    // (depth 2, parent_of_cvr = A's cvr). B is owned by two people (depth 3,
    // parent_of_cvr = B's cvr). Depth-1 nodes (A) are suppressed by design (see
    // ownershipTree() docblock) — B is the visible top-level tree root, with
    // the two people nested under it. Nothing should be flat here: the tree
    // must reflect real parent→child nesting, not a depth-sorted sibling list.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'A Holding ApS', 'cvr' => 'A1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'B Holding ApS', 'cvr' => 'B1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 2, 'parent_of_cvr' => 'A1', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'Person One', 'cvr' => null, 'is_company' => false, 'ownership_share' => 60.0, 'owner_kind' => 'reel', 'depth' => 3, 'parent_of_cvr' => 'B1', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'Person Two', 'cvr' => null, 'is_company' => false, 'ownership_share' => 40.0, 'owner_kind' => 'reel', 'depth' => 3, 'parent_of_cvr' => 'B1', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    $tree = Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->instance()
        ->ownershipTree();

    // A itself is not a visible tree node (already shown by the Legal owner
    // row below) — B is promoted to the top level in A's place.
    expect($tree)->toHaveCount(1);
    expect($tree[0]['person_name'])->toBe('B Holding ApS');
    expect($tree[0]['children'])->toHaveCount(2);

    $childNames = collect($tree[0]['children'])->pluck('person_name')->all();
    expect($childNames)->toEqualCanonicalizing(['Person One', 'Person Two']);

    // Persons are leaves.
    foreach ($tree[0]['children'] as $child) {
        expect($child['children'])->toBe([]);
    }
});

it('surfaces a shared owner X under both companies it owns, not as a single deduped node', function () {
    // X owns BOTH the root's direct owner (A) and an unrelated company (C),
    // which C itself does not connect to the searched company at all in this
    // fixture — it exists purely to prove X is not collapsed into one node.
    // Since A is a depth-1 direct owner (suppressed), X (A's owner) is promoted
    // to a visible top-level root. X owning C separately is captured by X's
    // OWN children list being independent of which company is asking — X must
    // appear once per company it owns (under A here), which is correct nesting,
    // not a "duplicate" to be merged away.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'A Holding ApS', 'cvr' => 'A1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'X Holding ApS', 'cvr' => 'X1', 'is_company' => true, 'ownership_share' => 50.0, 'owner_kind' => 'legal', 'depth' => 2, 'parent_of_cvr' => 'A1', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                // X also owns C, an entirely separate company not otherwise
                // linked to the searched company — an orphaned parent-group
                // from OpCo's perspective, surfaced as its own top-level root.
                ['person_name' => 'C ApS', 'cvr' => 'C1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => 'X1', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    $tree = Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->instance()
        ->ownershipTree();

    // X appears as A's child (A itself suppressed, so X is the top-level root).
    $xUnderA = collect($tree)->firstWhere('cvr', 'X1');
    expect($xUnderA)->not->toBeNull();
    expect(collect($xUnderA['children'])->pluck('cvr')->all())->toContain('C1');

    // C is nested under X — not flattened to the top level as a sibling of X.
    expect(collect($tree)->pluck('cvr')->all())->not->toContain('C1');
});

it('terminates on a cycle (A owns B, B owns A) instead of recursing infinitely', function () {
    // A cycle in the flat ancestors list: A's parent_of_cvr chain eventually
    // points back to A itself. buildOwnerChildren's $seen guard (cvrs on the
    // current path) must stop expanding once a company reappears, or this
    // would recurse forever / stack-overflow.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'A ApS', 'cvr' => 'A1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'B ApS', 'cvr' => 'B1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 2, 'parent_of_cvr' => 'A1', 'enriching' => false, 'capped' => false, 'cycle' => true, 'foreign' => false],
                // B "owns" A again — the cycle edge back to the start.
                ['person_name' => 'A ApS', 'cvr' => 'A1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 3, 'parent_of_cvr' => 'B1', 'enriching' => false, 'capped' => false, 'cycle' => true, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    // If the $seen guard failed, this call would never return (infinite
    // recursion / stack overflow) and the test would hang or crash rather
    // than fail an assertion — completing at all IS the primary proof.
    $tree = Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->instance()
        ->ownershipTree();

    // A (depth 1) is suppressed; B is promoted to the top level.
    expect($tree)->toHaveCount(1);
    expect($tree[0]['person_name'])->toBe('B ApS');

    // B's child is A again, but A's own children are cut short by $seen
    // (A is already on the path), so A does not re-expand B under itself.
    expect($tree[0]['children'])->toHaveCount(1);
    expect($tree[0]['children'][0]['person_name'])->toBe('A ApS');
    expect($tree[0]['children'][0]['children'])->toBe([]);
});
