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
        '*cvr/company-structure*' => Http::response(['data' => [
            'owners' => [
                ['person_name' => 'EJER A/S', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'role_label' => 'EJERREGISTER', 'is_current' => true],
            ],
            'subsidiaries' => [
                ['name' => 'EGEN DATTER ApS', 'cvr' => '33333333', 'ownership_share' => 100],
            ],
            // Direct owner also appears as a depth-1 ancestors row, exactly like
            // the real API response — the tree (not a separate row) now renders it.
            'ancestors' => [
                ['person_name' => 'EJER A/S', 'cvr' => '11111111', 'is_company' => true, 'ownership_share' => 50.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSee('EGEN DATTER ApS')
        ->assertSee('EJER A/S');
});

it('shows all owner kinds (reel, legal, other) as tree roots — no separate rows (Variant A)', function () {
    // The old "Ultimate beneficial owner" / "Legal owner" / "Other with
    // ownership share" rows are gone: the ownership tree is now the single
    // source, rendering every depth-1 direct owner as a tree root regardless
    // of owner_kind.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'owners' => [
                ['person_name' => 'UBO PERSON', 'is_company' => false, 'ownership_share' => 27.43, 'owner_kind' => 'reel', 'is_current' => true],
                ['person_name' => 'LEGAL HOLDING ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'owner_kind' => 'legal', 'is_current' => true],
                ['person_name' => 'DIREKTØR MED ANDEL', 'is_company' => false, 'ownership_share' => 5.0, 'owner_kind' => 'other', 'is_current' => true],
            ],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'UBO PERSON', 'cvr' => null, 'is_company' => false, 'ownership_share' => 27.43, 'owner_kind' => 'reel', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'LEGAL HOLDING ApS', 'cvr' => '11111111', 'is_company' => true, 'ownership_share' => 50.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'DIREKTØR MED ANDEL', 'cvr' => null, 'is_company' => false, 'ownership_share' => 5.0, 'owner_kind' => 'other', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSee('UBO PERSON')
        ->assertSee('LEGAL HOLDING')
        ->assertSee('DIREKTØR MED ANDEL')
        ->assertDontSee('Ultimate beneficial owner')
        ->assertDontSee('Legal owner')
        ->assertDontSee('Other with ownership share');
});

it('renders a depth-1 owner from the tree even when owner_kind is absent (stale cached payload)', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'owners' => [
                ['person_name' => 'OLD REEL', 'is_company' => false, 'ownership_share' => 27.0, 'role_label' => 'Reelle ejere', 'is_current' => true],
                ['person_name' => 'OLD LEGAL ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'role_label' => 'EJERREGISTER', 'is_current' => true],
            ],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'OLD REEL', 'cvr' => null, 'is_company' => false, 'ownership_share' => 27.0, 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'OLD LEGAL ApS', 'cvr' => '11111111', 'is_company' => true, 'ownership_share' => 50.0, 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertOk()
        ->assertSee('OLD REEL')
        ->assertSee('OLD LEGAL');
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
            // $owners (historical-owner data, unused for current owners since
            // Variant A) and $ancestors (depth 1), mirroring the real API shape.
            // The tree (built from $ancestors) is the single source that renders
            // BidCo now, as its depth-1 tree root.
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
    // getOwnershipChain's BFS starts at the searched company, so its depth-1
    // nodes are the immediate owners. Variant A: the tree is now the SINGLE
    // source rendering BidCo (as the tree root) — there is no separate row
    // that could duplicate it, so it must still appear exactly once.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [
                ['person_name' => 'BidCo ApS', 'is_company' => true, 'cvr' => '20000002', 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'is_current' => true],
            ],
            'subsidiaries' => [],
            'ancestors' => [
                // The tree root (depth 1) — rendered exactly once by the tree.
                ['person_name' => 'BidCo ApS', 'cvr' => '20000002', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                // Grandparent (depth 2) — nested under BidCo in the tree.
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

    // The depth-2 grandparent renders, nested under BidCo in the tree.
    expect($visibleHtml)->toContain('Top Ejer');
    // The depth-1 owner (BidCo ApS) appears exactly once in visible markup — as
    // the tree root — never a second time anywhere else.
    expect(substr_count($visibleHtml, 'BidCo ApS'))->toBe(1);
});

it('renders a tree node missing owner_kind without erroring (stale cached payload)', function () {
    // A cached/older ancestors payload can be missing owner_kind. The tree
    // partial (owner-card, ownership-tree-node) already null-coalesces
    // defensively — this must not throw an "Undefined array key" warning
    // (possible 500 under strict error handling) just because owner_kind
    // is absent from an orphaned deeper group's node.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                // parent_of_cvr references a company with no own ancestors row —
                // an orphaned group, surfaced as its own top-level tree root.
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

it('does not warn when rendering a tree node with a depth-3+ orphaned ancestor (defensive coalesce)', function () {
    // Depth is present but this proves the tree partial reads fields via the
    // same defensive null-coalesce pattern throughout, not a raw array access
    // that would warn if a future payload shape ever omitted a key.
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
    // parent_of_cvr = B's cvr). Variant A: depth-1 nodes (A) ARE included as
    // top-level tree roots (one full tree, top to bottom, matching the CVR
    // click-through) — nothing is suppressed or promoted. The tree must
    // reflect real parent→child nesting: A -> B -> [Person One, Person Two].
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

    // A (depth 1) IS the top-level tree root — the searched company's direct
    // owner, no longer suppressed.
    expect($tree)->toHaveCount(1);
    expect($tree[0]['person_name'])->toBe('A Holding ApS');
    expect($tree[0]['children'])->toHaveCount(1);

    // B is nested under A.
    $b = $tree[0]['children'][0];
    expect($b['person_name'])->toBe('B Holding ApS');
    expect($b['children'])->toHaveCount(2);

    $childNames = collect($b['children'])->pluck('person_name')->all();
    expect($childNames)->toEqualCanonicalizing(['Person One', 'Person Two']);

    // Persons are leaves.
    foreach ($b['children'] as $child) {
        expect($child['children'])->toBe([]);
    }
});

it('surfaces a shared owner X under both companies it owns, not as a single deduped node', function () {
    // X owns BOTH the root's direct owner (A) and an unrelated company (C),
    // which C itself does not connect to the searched company at all in this
    // fixture — it exists purely to prove X is not collapsed into one node.
    // A (depth 1) is now a visible top-level root (Variant A); X is nested
    // under it. X owning C separately is captured by X's OWN children list
    // being independent of which company is asking — X must appear once per
    // company it owns (under A here), which is correct nesting, not a
    // "duplicate" to be merged away.
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

    // A is the top-level root; X is nested one level under A.
    $a = collect($tree)->firstWhere('cvr', 'A1');
    expect($a)->not->toBeNull();
    $xUnderA = collect($a['children'])->firstWhere('cvr', 'X1');
    expect($xUnderA)->not->toBeNull();
    expect(collect($xUnderA['children'])->pluck('cvr')->all())->toContain('C1');

    // C is nested under X — not flattened to the top level as a sibling of A or X.
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

    // A (depth 1) is the visible top-level root (Variant A).
    expect($tree)->toHaveCount(1);
    expect($tree[0]['person_name'])->toBe('A ApS');

    // A's child is B, whose own child is A again, but A's own children are
    // cut short by $seen (A is already on the path), so it does not re-expand
    // B under itself a second time.
    expect($tree[0]['children'])->toHaveCount(1);
    $b = $tree[0]['children'][0];
    expect($b['person_name'])->toBe('B ApS');
    expect($b['children'])->toHaveCount(1);
    expect($b['children'][0]['person_name'])->toBe('A ApS');
    expect($b['children'][0]['children'])->toBe([]);
});

it('nests a foreign co-owner under the mid-chain company it owns (Standout Capital case)', function () {
    // Mirrors the registry-api fix: RS HoldCo (a mid-chain company, depth 2)
    // has a Danish legal owner AND a foreign co-owner (Standout Capital II AB,
    // foreign: true, parent_of_cvr = RS HoldCo's cvr). The tree must nest the
    // foreign node under RS HoldCo, not drop it or float it at the top level.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'Resights ApS',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'RS BidCo ApS', 'cvr' => 'BIDCO', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'RS HoldCo ApS', 'cvr' => '45429075', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 2, 'parent_of_cvr' => 'BIDCO', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'Lars Horsbøl Holding ApS', 'cvr' => 'LARS1', 'is_company' => true, 'ownership_share' => 20.0, 'owner_kind' => 'legal', 'depth' => 3, 'parent_of_cvr' => '45429075', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'Standout Capital II AB', 'cvr' => null, 'is_company' => true, 'ownership_share' => 55.0, 'owner_kind' => 'legal', 'depth' => 3, 'parent_of_cvr' => '45429075', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => true],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'Resights ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    $tree = Livewire::test(CompanyStructure::class, ['query' => '99000001'])
        ->instance()
        ->ownershipTree();

    // RS BidCo (depth 1) is the tree root.
    expect($tree)->toHaveCount(1);
    expect($tree[0]['person_name'])->toBe('RS BidCo ApS');

    // RS HoldCo is nested under RS BidCo.
    $holdco = $tree[0]['children'][0];
    expect($holdco['person_name'])->toBe('RS HoldCo ApS');
    expect($holdco['children'])->toHaveCount(2);

    // Both the Danish legal owner AND the foreign co-owner nest under RS HoldCo.
    $names = collect($holdco['children'])->pluck('person_name')->all();
    expect($names)->toEqualCanonicalizing(['Lars Horsbøl Holding ApS', 'Standout Capital II AB']);

    $standout = collect($holdco['children'])->firstWhere('person_name', 'Standout Capital II AB');
    expect($standout['foreign'])->toBeTrue();
    expect($standout['cvr'])->toBeNull();
    expect($standout['children'])->toBe([]);
});

it('renders the foreign marker for a nested foreign co-owner node in the org-chart card', function () {
    // The org-chart marks a foreign co-owner via its mono kind-label
    // ("Udenlandsk", oxblood #7a1f1f) inside the card — not a left-stripe
    // border and not a separate "foreign owner" text marker.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'Resights ApS',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'RS HoldCo ApS', 'cvr' => '45429075', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'Standout Capital II AB', 'cvr' => null, 'is_company' => true, 'ownership_share' => 55.0, 'owner_kind' => 'legal', 'depth' => 2, 'parent_of_cvr' => '45429075', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => true],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'Resights ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    $html = Livewire::test(CompanyStructure::class, ['query' => '99000001'])
        ->assertSee('RS HoldCo ApS')
        ->assertSee('Standout Capital II AB')
        ->assertSee('Udenlandsk')
        ->html();

    // Foreign owner_kind color (oxblood) is applied via the --m CSS var on the
    // mono .ckind label — never as a left-stripe border on the card itself
    // (banned AI-tell). `.org ul...::before` legitimately uses border-left for
    // the classic org-chart connector lines, so assert on `.card` specifically.
    expect($html)->toContain('--m: #7a1f1f');
    expect($html)->not->toMatch('/\.card\s*\{[^}]*border-left/');
});

// ---- Fase 1: graf-model (nodes + edges) til dagre-render ----

it('builds a flat graph model with nodes and edges from ancestors', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo', 'owners' => [], 'subsidiaries' => [],
            'ancestors' => [
                ['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false],
                ['person_name'=>'Top Ejer','cvr'=>null,'is_company'=>false,'ownership_share'=>100.0,'owner_kind'=>'reel','depth'=>2,'parent_of_cvr'=>'20000002','foreign'=>false,'cycle'=>false,'enriching'=>false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $g = Livewire::test(CompanyStructure::class, ['query'=>'20000001'])->instance()->ownershipGraphData();

    $ids = collect($g['nodes'])->pluck('id');
    // searched company + HoldCo (cvr id) + a person node
    expect($ids)->toContain('searched');
    expect($ids)->toContain('20000002');
    expect(collect($g['nodes'])->firstWhere('kind', 'person'))->not->toBeNull();
    // edge: HoldCo owns the searched company (parent_of_cvr null => owns searched)
    $edgeToSearched = collect($g['edges'])->firstWhere('to', 'searched');
    expect($edgeToSearched['from'])->toBe('20000002');
    // edge label carries a percentage/interval
    expect($edgeToSearched['label'])->toContain('%');
    // no duplicate node ids (dedup)
    expect($ids->count())->toBe($ids->unique()->count());
});

it('graph model marks a foreign owner node as foreign kind', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name'=>'OpCo','owners'=>[],'subsidiaries'=>[],
            'ancestors'=>[
                ['person_name'=>'DK Holding ApS','cvr'=>'30000001','is_company'=>true,'ownership_share'=>50.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false],
                ['person_name'=>'Standout Capital II AB','cvr'=>null,'is_company'=>true,'ownership_share'=>50.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>true,'cycle'=>false,'enriching'=>false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $g = Livewire::test(CompanyStructure::class, ['query'=>'30000000'])->instance()->ownershipGraphData();
    $foreign = collect($g['nodes'])->firstWhere('label', 'Standout Capital II AB');
    expect($foreign)->not->toBeNull();
    expect($foreign['kind'])->toBe('foreign');
});
