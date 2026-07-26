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

/**
 * Fakes company-structure (3-level subsidiary tree fixture from Task 3:
 * 44507781 -> 44018942 -> 44027992) + a completed enrichment status, so
 * expandNode('sub:44018942') has a real depth-3 child to reveal.
 */
function fakeRegistryStructure(): void
{
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [],
            'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0,
                'children' => [[
                    'cvr' => '44018942', 'name' => 'Trygve 1 ApS', 'ownership_share' => 100.0,
                    'children' => [[
                        'cvr' => '44027992', 'name' => 'Schneidereit Trygve 1 A/S', 'ownership_share' => 67.0, 'children' => [],
                    ]],
                ]],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
}

/**
 * Fakes the property-portfolio + properties/batch endpoints. Response shape
 * matches RegistryApi::fetchCompanyPropertyPortfolio's real payload: the
 * properties/count live under data.portfolio (see CompanyOverview/CompanyProperties,
 * which consume the same method the same way) — NOT flat on the outer array.
 *
 * $batchUsage, when set, attaches a single primary building (BBR code 130 —
 * "Bolig" per BbrUsageCategory) to each property's batch response so
 * usageMapFor() has something to resolve; the param only toggles whether a
 * building is present (BbrUsageCategory always maps 130 -> 'Bolig', it does
 * not echo back an arbitrary string).
 */
function fakeRegistryPortfolio(array $properties = [], ?string $batchUsage = null, ?int $propertyCount = null): void
{
    $count = $propertyCount ?? count($properties);

    Http::fake([
        '*/property-portfolio*' => Http::response(['data' => [
            'portfolio' => [
                'properties' => $properties,
                'property_count' => $count,
                'total_count' => $count,
            ],
        ]]),
        '*/properties/batch*' => Http::response(['data' => collect($properties)->map(fn ($p) => [
            'matrikel_id' => $p['matrikel_id'] ?? null,
            'bbr' => ['buildings' => $batchUsage === null ? [] : [
                ['usage' => 130, 'total_area' => 150],
            ]],
        ])->all()]),
    ]);
}

function fdlPortfolioProperty(array $overrides = []): array
{
    return array_merge([
        'owner_cvr' => '38653806', 'matrikel_id' => '2573669', 'is_matriculated' => true,
        'address' => 'Kongshøjvej 2', 'city' => 'Store Heddinge', 'valuation' => 534000,
    ], $overrides);
}

/**
 * Fakes the pooled company-info endpoint (/v1/cvr/company/{cvr}) used by
 * loadEnrichment() via fetchCompanyInfosPooled(). $financials mirrors
 * CompanyOverview.php's fixture shape (year/equity/assets/profit_loss) —
 * the SAME field names the real API returns, so loadEnrichment must read
 * financials the same way CompanyOverview does, not invent new field names.
 */
function fakeRegistryCompanyInfo(string $cvr, array $overrides = []): void
{
    Http::fake([
        "*/v1/cvr/company/{$cvr}*" => Http::response(['data' => ['company' => array_merge([
            'cvr' => $cvr,
            'name' => 'Kirketorvet Ejendomme ApS',
            'employees' => 4,
            'founded_date' => '2010-01-01',
            'industry' => 'Ejendomshandel',
            'contact' => ['website' => 'kirketorvet.dk'],
            'financials' => [
                ['year' => '2024', 'equity' => 1_000_000, 'assets' => 5_000_000, 'profit_loss' => 100_000],
                ['year' => '2023', 'equity' => 900_000, 'assets' => 4_500_000, 'profit_loss' => 50_000],
            ],
        ], $overrides)]]),
    ]);
}

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

    $test = Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertDontSee('FREMMED DATTER');

    expect(collect($test->get('graphModel')['nodes'])->pluck('label'))->not->toContain('FREMMED DATTER A/S');
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

    // Subsidiaries and owners now live only in the graph's @js payload
    // (x-data), not as separate text rows — assert against the mounted
    // graph model instead of visible text.
    $test = Livewire::test(CompanyStructure::class, ['query' => '99999999']);

    $graph = $test->instance()->graphModel;
    expect(collect($graph['nodes'])->pluck('label'))->toContain('EJER A/S')
        ->and(collect($graph['nodes'])->pluck('label'))->toContain('EGEN DATTER ApS');
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

    // All three depth-1 owners become graph nodes (the tree/graph is the single
    // source); the old separate role rows stay gone.
    $test = Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertDontSee('Ultimate beneficial owner')
        ->assertDontSee('Legal owner')
        ->assertDontSee('Other with ownership share');

    $labels = collect($test->instance()->graphModel['nodes'])->pluck('label');
    expect($labels)->toContain('UBO PERSON')
        ->and($labels)->toContain('LEGAL HOLDING ApS')
        ->and($labels)->toContain('DIREKTØR MED ANDEL');
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

    $test = Livewire::test(CompanyStructure::class, ['query' => '70000001']);

    expect(collect($test->get('graphModel')['nodes'])->pluck('label'))->toContain('HoldCo ApS');
});

it('chains ancestors above the searched company via edges, deepest owner at the top', function () {
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

    // Graph layout is now computed client-side (dagre/Alpine), so "deepest at
    // top" is no longer a server-rendered text order — assert the underlying
    // ownership chain instead: BidCo owns 'searched', Top Ejer owns BidCo.
    $g = Livewire::test(CompanyStructure::class, ['query' => '70000001'])->instance()->graphModel;

    $bidco = collect($g['nodes'])->firstWhere('label', 'BidCo ApS');
    $topEjer = collect($g['nodes'])->firstWhere('label', 'Top Ejer');
    expect($bidco)->not->toBeNull()->and($topEjer)->not->toBeNull();
    expect(collect($g['edges'])->firstWhere('from', $bidco['id'])['to'])->toBe('searched');
    expect(collect($g['edges'])->firstWhere('from', $topEjer['id'])['to'])->toBe($bidco['id']);
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

    $test = Livewire::test(CompanyStructure::class, ['query' => '70000001']);

    // The graph is JS-rendered from graphModel, so "no double-render"
    // is now a model-level property: each owner is one node, not two. (Labels
    // appear in the x-data + carrier JSON payloads, which is not a visual render.)
    $g = $test->instance()->graphModel;
    expect(collect($g['nodes'])->where('label', 'BidCo ApS'))->toHaveCount(1);
    expect(collect($g['nodes'])->where('label', 'Top Ejer'))->toHaveCount(1);
    // BidCo owns searched (depth 1); Top Ejer owns BidCo (depth 2) — distinct edges.
    expect(collect($g['edges'])->firstWhere('from', '20000002')['to'])->toBe('searched');
    $topEjer = collect($g['nodes'])->firstWhere('label', 'Top Ejer');
    expect(collect($g['edges'])->firstWhere('from', $topEjer['id'])['to'])->toBe('20000002');
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

it('builds a cycle-containing graph model without infinite recursion (A owns B, B owns A)', function () {
    // The flat graph model iterates $ancestors once (no recursion), so a cycle
    // cannot infinite-loop — but assert it completes and yields the expected
    // nodes/edges, preserving the cycle coverage the old tree test gave.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo', 'owners' => [], 'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'A ApS', 'cvr' => 'A1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'foreign' => false, 'cycle' => false, 'enriching' => false],
                ['person_name' => 'B ApS', 'cvr' => 'B1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 2, 'parent_of_cvr' => 'A1', 'foreign' => false, 'cycle' => true, 'enriching' => false],
                ['person_name' => 'A ApS', 'cvr' => 'A1', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 3, 'parent_of_cvr' => 'B1', 'foreign' => false, 'cycle' => true, 'enriching' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    $g = Livewire::test(CompanyStructure::class, ['query' => '70000001'])->instance()->graphModel;

    // A and B each appear once (cvr dedup across the cycle rows); no runaway.
    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('A1')->toContain('B1');
    expect(collect($g['nodes'])->where('id', 'A1')->count())->toBe(1);
    // edges: A->searched, B->A, A->B (the cycle edge), deduped on from|to.
    $pairs = collect($g['edges'])->map(fn ($e) => $e['from'].'->'.$e['to'])->all();
    expect($pairs)->toContain('A1->searched')->toContain('B1->A1')->toContain('A1->B1');
});

it('marks a nested foreign co-owner as a foreign graph node with oxblood styling and no left-stripe', function () {
    // The graph marks a foreign co-owner via kind='foreign' on its node; the
    // .mgraph-node--foreign CSS applies the oxblood accent (#7a1f1f) as border
    // + name colour — never as a left-stripe border (banned AI-tell).
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

    $test = Livewire::test(CompanyStructure::class, ['query' => '99000001']);
    $nodes = collect($test->instance()->graphModel['nodes']);

    $standout = $nodes->firstWhere('label', 'Standout Capital II AB');
    expect($standout['kind'])->toBe('foreign');

    // oxblood accent defined; no left-stripe on the node card.
    $html = $test->html();
    expect($html)->toContain('--foreign:#7a1f1f');
    expect($html)->not->toMatch('/\.mgraph-node\s*\{[^}]*border-left/');
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

    $g = Livewire::test(CompanyStructure::class, ['query'=>'20000001'])->instance()->graphModel;

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

    $g = Livewire::test(CompanyStructure::class, ['query'=>'30000000'])->instance()->graphModel;
    $foreign = collect($g['nodes'])->firstWhere('label', 'Standout Capital II AB');
    expect($foreign)->not->toBeNull();
    expect($foreign['kind'])->toBe('foreign');
});

it('renders the ownership graph container mounting Alpine with node data', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name'=>'OpCo','owners'=>[],'subsidiaries'=>[],
            'ancestors'=>[['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false]],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $html = Livewire::test(CompanyStructure::class, ['query'=>'20000001'])->html();

    // graph container mounts the Alpine component with the model
    expect($html)->toContain('x-data="ownershipGraph(');
    // wire:ignore so Livewire morphing never touches the dagre-rendered DOM
    expect($html)->toContain('wire:ignore');
    // node label present in the mounted data
    expect($html)->toContain('HoldCo ApS');
});

// ---- Review-fund fase 1: 2×P1 + 1×P2 ----

it('synthesises a stub node for an orphaned parent_of_cvr so the chain stays connected (P1 orphan-edge)', function () {
    // A deep owner references a parent company (44444444) that has NO row of its
    // own in $ancestors (capped/pruned upstream). Without a stub node, the JS
    // edge-filter drops the edge and the owner floats detached, reading as a
    // top-UBO it is not. The model must synthesise a stub so every edge endpoint
    // is a real node.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo', 'owners' => [], 'subsidiaries' => [],
            'ancestors' => [
                ['person_name'=>'Deep Owner ApS','cvr'=>'55555555','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>2,'parent_of_cvr'=>'44444444','foreign'=>false,'cycle'=>false,'enriching'=>false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $g = Livewire::test(CompanyStructure::class, ['query'=>'11111111'])->instance()->graphModel;

    $ids = collect($g['nodes'])->pluck('id');
    // the orphan parent now exists as a node (stub) → its edge endpoint is real
    expect($ids)->toContain('44444444');
    // every edge endpoint resolves to a node (no dangling edge → no detached node)
    foreach ($g['edges'] as $e) {
        expect($ids)->toContain($e['from']);
        expect($ids)->toContain($e['to']);
    }
});

it('gives two same-named persons owning the same company distinct node ids (P2 id-collision)', function () {
    // Two distinct "Jens Hansen" each own 50% of the searched company. Keying a
    // person node on name|parent alone collapses them into one node and drops
    // the second owner. Each ancestor row must yield its own node.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo', 'owners' => [], 'subsidiaries' => [],
            'ancestors' => [
                ['person_name'=>'Jens Hansen','cvr'=>null,'is_company'=>false,'ownership_share'=>50.0,'owner_kind'=>'reel','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false],
                ['person_name'=>'Jens Hansen','cvr'=>null,'is_company'=>false,'ownership_share'=>50.0,'owner_kind'=>'reel','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $g = Livewire::test(CompanyStructure::class, ['query'=>'22222222'])->instance()->graphModel;

    // both persons survive as distinct nodes
    $persons = collect($g['nodes'])->where('label', 'Jens Hansen');
    expect($persons)->toHaveCount(2);
    // and both own the searched company (two distinct edges)
    $edges = collect($g['edges'])->where('to', 'searched');
    expect($edges)->toHaveCount(2);
});

it('exposes the graph model as watchable Livewire state with a stable graph key (P1 stale-graph, no mid-pan remount)', function () {
    // The graph wrapper has a STABLE wire:key (query only) so Livewire never
    // re-mounts it — the user's zoom/pan is Alpine state that must survive an
    // enrichment poll. The model is a public `graphModel` property; the Alpine
    // graph does $wire.$watch('graphModel', …) to re-lay-out in place when a poll
    // deepens the chain. No carrier node, no dispatch-before-listener race.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name'=>'OpCo','owners'=>[],'subsidiaries'=>[],
            'ancestors'=>[['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false]],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $test = Livewire::test(CompanyStructure::class, ['query'=>'20000001']);

    // graphModel is populated public state (so $wire.$watch has something to see).
    $test->assertSet('graphModel', fn ($m) => is_array($m) && count($m['nodes']) === 2);

    // graph wrapper: STABLE key (query only) — never re-mounted mid-pan
    expect($test->html())->toContain('wire:key="ownership-graph-20000001"');
    // the carrier/dispatch bridge is gone
    expect($test->html())->not->toContain('graph-model-updated');
});

// ---- Review-fund #2 (fase 1 PR-review): 3×P2 ----

it('treats a depth-1 owner whose parent_of_cvr equals the searched cvr as owning searched — no duplicate root node (P2)', function () {
    // If the backend fills parent_of_cvr with the searched company's own CVR
    // (instead of null) for a direct owner, the naive model both points the edge
    // at that cvr AND synthesises a stub node for it → a duplicate of the searched
    // company beside the real 'searched' node, with ownership split across two
    // roots. The model must normalise parent_of_cvr === query to 'searched'.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo', 'owners' => [], 'subsidiaries' => [],
            'ancestors' => [
                ['person_name'=>'Direct Owner ApS','cvr'=>'70000009','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>'33333333','foreign'=>false,'cycle'=>false,'enriching'=>false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    // query IS the parent_of_cvr → the owner really owns the searched company
    $g = Livewire::test(CompanyStructure::class, ['query'=>'33333333'])->instance()->graphModel;

    $ids = collect($g['nodes'])->pluck('id');
    // no duplicate root: '33333333' must NOT appear as a separate node (only 'searched')
    expect($ids)->not->toContain('33333333');
    // exactly one node carries the searched cvr
    expect(collect($g['nodes'])->where('cvr', '33333333')->count())->toBe(1);
    // the owner's edge points at 'searched', not at the cvr
    expect(collect($g['edges'])->firstWhere('from', '70000009')['to'])->toBe('searched');
});

it('deduplicates identical repeated ancestor edges so no double line or overlapping label renders (P3→folded into P2 pass)', function () {
    // The same owner row arriving twice (backend BFS quirk) must not produce two
    // overlapping edges with two stacked % labels. Dedup edges on from|to.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo', 'owners' => [], 'subsidiaries' => [],
            'ancestors' => [
                ['person_name'=>'Repeated ApS','cvr'=>'40000001','is_company'=>true,'ownership_share'=>50.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false],
                ['person_name'=>'Repeated ApS','cvr'=>'40000001','is_company'=>true,'ownership_share'=>50.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $g = Livewire::test(CompanyStructure::class, ['query'=>'40000000'])->instance()->graphModel;

    // one node (already deduped) AND one edge (new dedup)
    expect(collect($g['nodes'])->where('id', '40000001')->count())->toBe(1);
    expect(collect($g['edges'])->where('from', '40000001')->where('to', 'searched')->count())->toBe(1);
});

it('rebuilds graphModel when an enrichment poll deepens the chain, so the watcher fires (P1 stale-graph)', function () {
    // Mid-enrichment: mount returns a shallow chain, then pollForUpdates on
    // completion refetches a deeper one. graphModel must be rebuilt from the
    // deeper chain so the Alpine $wire.$watch('graphModel') fires and re-lays-out.
    Http::fake([
        '*cvr/company-structure*' => Http::sequence()
            // mount: still enriching, shallow chain (1 owner → 2-node model)
            ->push(['data' => [
                'name'=>'OpCo','owners'=>[],'subsidiaries'=>[],
                'ancestors'=>[['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false]],
            ]])
            // poll: completed, deeper chain (2 owners → 3-node model)
            ->push(['data' => [
                'name'=>'OpCo','owners'=>[],'subsidiaries'=>[],
                'ancestors'=>[
                    ['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false],
                    ['person_name'=>'Top Ejer','cvr'=>null,'is_company'=>false,'ownership_share'=>100.0,'owner_kind'=>'reel','depth'=>2,'parent_of_cvr'=>'20000002','foreign'=>false,'cycle'=>false,'enriching'=>false],
                ],
            ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        // mount sees enrichment running so pollForUpdates does real work; poll sees completed.
        '*enrichment*' => Http::sequence()
            ->push(['data'=>['status'=>'running']])
            ->push(['data'=>['status'=>'completed']]),
    ]);

    $test = Livewire::test(CompanyStructure::class, ['query'=>'20000001']);
    expect(count($test->instance()->graphModel['nodes']))->toBe(2);   // shallow at mount

    $test->call('pollForUpdates');
    expect(count($test->instance()->graphModel['nodes']))->toBe(3);   // deepened → watcher fires
});

// ---- Task 7: declarative builder integration ----

it('rebuilds the graph declaratively when a node is expanded — poll cannot wipe it', function () {
    fakeRegistryStructure();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('expandNode', 'sub:44018942');

    expect(collect($c->get('graphModel')['nodes'])->pluck('id'))->toContain('44027992');

    // A subsequent poll (rebuild from source) must NOT lose the expansion:
    $c->call('pollForUpdates');
    expect(collect($c->get('graphModel')['nodes'])->pluck('id'))->toContain('44027992');
});

it('loads properties async and merges them via rebuild', function () {
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'Fritliggende enfamiliehus');

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded')
        ->and(collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->not->toBeNull();
});

it('reports building when the portfolio is still assembling — never silently empty', function () {
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [], propertyCount: 13);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('building');
});

it('reports failed when the portfolio call errors — never silently empty', function () {
    fakeRegistryStructure();
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
        '*/property-portfolio*' => Http::response(null, 500),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('failed');
});

it('rehydrates structureData before expanding after a fresh request (protected state does not survive hydration)', function () {
    // Two SEPARATE Livewire::test() calls simulate two separate requests: the
    // second call's component instance starts with $structureData === [] since
    // protected properties are not part of the wire payload. expandNode must
    // detect that and refresh from source before rebuilding, or the expansion
    // would silently build against an empty structure.
    fakeRegistryStructure();
    $first = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    fakeRegistryStructure();
    $second = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('expandNode', 'sub:44018942');

    expect(collect($second->get('graphModel')['nodes'])->pluck('id'))->toContain('44027992');
});

it('rehydrates structureData before loading properties after a fresh request', function () {
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'Fritliggende enfamiliehus');

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    // Subsidiaries from structureData must still be present in the rebuilt
    // graph alongside the newly-loaded property — proving loadProperties()
    // rebuilt from a non-empty structureData, not an empty one.
    $ids = collect($c->get('graphModel')['nodes'])->pluck('id');
    expect($ids)->toContain('44507781'); // top-level subsidiary from the fixture
});

it('casts an integer matrikel_id from the portfolio payload to string in the usage map (builder/usage-map robustness)', function () {
    fakeRegistryStructure();
    // matrikel_id arrives as an INT in the portfolio payload (backend quirk).
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty(['matrikel_id' => 2573669])], batchUsage: 'present');

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded');
    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop)->not->toBeNull()
        ->and($prop['id'])->toBe('bfe:2573669')
        ->and($prop['meta']['usage'])->toBe('Bolig'); // BBR code 130 -> 'Bolig' via BbrUsageCategory
});

it('treats a properties/batch failure as usage-less (not a portfolio failure) — properties still render, propertiesStatus stays loaded', function () {
    fakeRegistryStructure();
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
        '*/property-portfolio*' => Http::response(['data' => [
            'portfolio' => [
                'properties' => [fdlPortfolioProperty(['owner_cvr' => '44507781'])],
                'property_count' => 1,
                'total_count' => 1,
            ],
        ]]),
        '*/properties/batch*' => Http::response(null, 500),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded');
    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop)->not->toBeNull()
        ->and($prop['meta']['usage'])->toBeNull();
});

// ---- Review fix: $propertyData is lost on hydration too (P0) ----

it('keeps property nodes in the graph when expandNode runs in a separate request after loadProperties (P0 hydration)', function () {
    // Request A: loadProperties() succeeds — propertiesStatus becomes 'loaded'
    // and the property node appears in graphModel.
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'present');

    $first = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');
    expect($first->get('propertiesStatus'))->toBe('loaded');
    expect(collect($first->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->not->toBeNull();

    // Request B: a FRESH component instance (protected $propertyData lost to
    // hydration, but the public propertiesStatus — hydrated normally — still
    // says 'loaded'). expandNode() must detect the mismatch and refetch the
    // portfolio before rebuilding, or the property node silently disappears.
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'present');

    $second = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->set('propertiesStatus', 'loaded')
        ->call('expandNode', 'sub:44018942');

    $nodes = collect($second->get('graphModel')['nodes']);
    expect($nodes->firstWhere('kind', 'property'))->not->toBeNull();
    expect($nodes->pluck('id'))->toContain('44027992'); // the expand itself still worked
});

it('keeps property nodes in the graph when pollForUpdates completes in a separate request after loadProperties (P0 hydration)', function () {
    // Http::fake() APPENDS stubs (first URL match wins), so a test that needs
    // the SAME endpoint to answer differently across two "requests" must
    // declare every response inside one Http::fake() call, driven by a
    // request-sequence-aware closure — a second Http::fake() call would just
    // add a stub the first one's pattern already shadows.
    $enrichmentCalls = 0;
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => function () use (&$enrichmentCalls) {
            $enrichmentCalls++;

            // Request A's mount (call 1) + request B's mount (call 2) both see
            // 'running' so $enriching=true on request B and pollForUpdates()
            // does real work; the explicit pollForUpdates call (call 3) sees
            // 'completed'.
            return Http::response(['data' => ['status' => $enrichmentCalls >= 3 ? 'completed' : 'running']]);
        },
        '*/property-portfolio*' => Http::response(['data' => [
            'portfolio' => [
                'properties' => [fdlPortfolioProperty()],
                'property_count' => 1,
                'total_count' => 1,
            ],
        ]]),
        '*/properties/batch*' => Http::response(['data' => []]),
    ]);

    // Request A: loadProperties() succeeds — propertiesStatus becomes 'loaded'.
    $first = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');
    expect($first->get('propertiesStatus'))->toBe('loaded');

    // Request B: a FRESH component instance — protected $propertyData is lost
    // to hydration, but the public $propertiesStatus (hydrated normally, and
    // forced here to simulate a real hydrated request) still says 'loaded'.
    // pollForUpdates() completing must detect the mismatch and refetch the
    // portfolio before rebuilding, or the property node silently disappears.
    $second = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->set('propertiesStatus', 'loaded')
        ->call('pollForUpdates');

    expect(collect($second->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->not->toBeNull();
});

// ---- Review fix: propertiesAttempts cap (P2b) ----

it('flips to failed after MAX_PROPERTIES_ATTEMPTS building attempts — never polls forever', function () {
    fakeRegistryStructure();
    // property_count > 0 but properties always empty: the portfolio never finishes.
    fakeRegistryPortfolio(properties: [], propertyCount: 13);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    for ($i = 0; $i < 8; $i++) {
        $c->call('loadProperties');
    }

    expect($c->get('propertiesStatus'))->toBe('failed')
        ->and($c->get('propertiesAttempts'))->toBe(8);
});

// ---- Final review fase 2a.1: F1 — portfolio fetch must request limit 500 ----

it('requests the property portfolio with limit 500, matching CompanyOverviews warm cache (F1)', function () {
    // Default API limit is 25 — without an explicit limit, large koncerner
    // (e.g. JEUDAN's 649 properties) would silently page in only the first
    // 25, making the node-cap/expand counts a lie. limit: 500 also matches
    // CompanyOverview.php:40's call, so this hits the same cache key.
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'present');

    Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/property-portfolio')
        && $request['limit'] === 500);
});

it('rehydrated refreshPropertyData also requests limit 500 (F1)', function () {
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'present');

    $first = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');
    expect($first->get('propertiesStatus'))->toBe('loaded');

    // fetchCompanyPropertyPortfolio() caches on {cvr}:{limit}:{offset} (Task
    // 6). Force a cache miss so the second component's rehydration path
    // actually re-fetches, instead of the test passing vacuously off the
    // first call's cached response.
    \Illuminate\Support\Facades\Cache::flush();

    // Fresh request: protected $propertyData is lost to hydration, forcing
    // rehydrateBeforeRebuild() -> refreshPropertyData() to re-fetch.
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'present');

    Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->set('propertiesStatus', 'loaded')
        ->call('expandNode', 'sub:44018942');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/property-portfolio')
        && $request['limit'] === 500);
});

it('retryProperties resets propertiesAttempts so the cap does not carry over', function () {
    fakeRegistryStructure();

    // Http::fake() appends stubs (first URL match wins), so the portfolio
    // endpoint must switch behaviour via one call-count-aware closure rather
    // than a second Http::fake() — see the pollForUpdates P0 test above.
    $portfolioCalls = 0;
    Http::fake([
        '*/property-portfolio*' => function () use (&$portfolioCalls) {
            $portfolioCalls++;

            // First 8 calls (the cap-exhausting loop): building forever.
            // 9th call (after retryProperties resets attempts): succeeds.
            return $portfolioCalls <= 8
                ? Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 13, 'total_count' => 13]]])
                : Http::response(['data' => ['portfolio' => [
                    'properties' => [fdlPortfolioProperty()], 'property_count' => 1, 'total_count' => 1,
                ]]]);
        },
        '*/properties/batch*' => Http::response(['data' => []]),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);
    for ($i = 0; $i < 8; $i++) {
        $c->call('loadProperties');
    }
    expect($c->get('propertiesStatus'))->toBe('failed');

    $c->call('retryProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded')
        ->and($c->get('propertiesAttempts'))->toBe(0);
});

// ---- Task 3: enrichment step — pooled financials, full batch map, streetview URL ----

it('loadEnrichment attaches company card/signals to graph nodes', function () {
    // Http::fake() matches the FIRST-registered pattern (see the pollForUpdates
    // P0 test's comment above) — the specific per-cvr fakes must be registered
    // BEFORE fakeRegistryStructure()'s generic '*cvr/company/*' catch-all, or
    // the wildcard shadows them and every cvr gets the bare fallback fixture.
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');
    fakeRegistryStructure();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadEnrichment');

    expect($c->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    expect($node['card'] ?? null)->not->toBeNull()
        ->and($node['card']['equity'])->toBe(1_000_000)
        ->and($node['card']['fiscal_year'])->toBe('2024')
        ->and($node['card']['website'])->toBe('kirketorvet.dk')
        ->and($node['card']['industry'])->toBe('Ejendomshandel')
        ->and($node['card']['founded_date'])->toBe('2010-01-01')
        ->and($node['card']['employees'])->toBe(4);
});

it('reads the LATEST financials row for equity/result/fiscal_year even when the financials list arrives unsorted', function () {
    // Guards against the builder-review note: loadEnrichment (not the
    // builder) must reduce an unsorted financials array to its latest row —
    // mirroring CompanyOverview's newest-first assumption is NOT safe to
    // assume blindly, so this fixture deliberately scrambles the order.
    // NB: this test defines its OWN complete fake set (not fakeRegistryStructure())
    // because the generic '*cvr/company/*' fallback that helper registers would
    // otherwise shadow the specific financials fixture below (first-match-wins).
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => [
            'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS',
            'financials' => [
                // Deliberately unsorted: the 2022 row (oldest) is listed FIRST,
                // and the actual latest (2024) is listed LAST.
                ['year' => '2022', 'equity' => -500_000, 'assets' => 1_000_000, 'profit_loss' => -50_000],
                ['year' => '2024', 'equity' => 2_000_000, 'assets' => 6_000_000, 'profit_loss' => 300_000],
                ['year' => '2023', 'equity' => 1_500_000, 'assets' => 5_000_000, 'profit_loss' => 150_000],
            ],
        ]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadEnrichment');

    $node = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    // Must resolve to the 2024 row (the actual latest year), not the first
    // or last array element.
    expect($node['card']['equity'])->toBe(2_000_000)
        ->and($node['card']['fiscal_year'])->toBe('2024');
});

it('gates loadEnrichment while enriching — stays pending, no company-info calls', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        // Still running: $enriching stays true after mount.
        '*enrichment*' => Http::response(['data' => ['status' => 'running']]),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);
    expect($c->get('enriching'))->toBeTrue();

    $c->call('loadEnrichment');

    expect($c->get('enrichmentStatus'))->toBe('pending');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/cvr/company/44507781'));
});

it('pool partial failure leaves the other companies enriched — only the failed cvr is null', function () {
    // Own complete fake set (not fakeRegistryStructure()) — see the unsorted-
    // financials test's comment above for why the two can't be combined.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0,
                'children' => [[
                    'cvr' => '44018942', 'name' => 'Trygve 1 ApS', 'ownership_share' => 100.0, 'children' => [],
                ]],
            ]],
        ]]),
        '*cvr/company/44507781*' => Http::response(['data' => ['company' => [
            'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS',
            'financials' => [['year' => '2024', 'equity' => 1_000_000, 'assets' => 5_000_000, 'profit_loss' => 100_000]],
        ]]]),
        '*cvr/company/44018942*' => Http::response('Server error', 500),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadEnrichment');

    expect($c->get('enrichmentStatus'))->toBe('loaded');

    $ok = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    $failed = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44018942');
    expect($ok['card'] ?? null)->not->toBeNull();
    expect($failed['card'] ?? null)->toBeNull();
    expect($failed['signals'] ?? null)->toBeNull();
});

it('flips enrichmentStatus to failed when the whole pooled call throws, with retry available', function () {
    fakeRegistryStructure();
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    // Force fetchCompanyInfosPooled() to throw entirely (not per-cvr null) —
    // simulate a total outage by binding a RegistryApi double that throws.
    $mock = Mockery::mock(\TheFountainhead\Metis\Services\RegistryApi::class)->makePartial();
    $mock->shouldReceive('fetchCompanyInfosPooled')->andThrow(new \RuntimeException('pool down'));
    app()->instance(\TheFountainhead\Metis\Services\RegistryApi::class, $mock);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadEnrichment');

    expect($c->get('enrichmentStatus'))->toBe('failed');
});

it('builds a streetview URL per property only when lat/lng exist AND the google maps api key is configured', function () {
    config(['metis.google_maps_api_key' => 'test-key-123']);

    fakeRegistryStructure();
    fakeRegistryPortfolio(
        properties: [fdlPortfolioProperty(['latitude' => 55.25, 'longitude' => 12.17])],
        batchUsage: 'present',
    );
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties')
        ->call('loadEnrichment');

    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop['card']['streetview_url'] ?? null)->not->toBeNull()
        ->and($prop['card']['streetview_url'])->toContain('test-key-123')
        ->and($prop['card']['streetview_url'])->toContain('55.25')
        ->and($prop['card']['streetview_url'])->toContain('12.17');
});

it('omits the streetview URL when the google maps api key is not configured', function () {
    config(['metis.google_maps_api_key' => null]);

    fakeRegistryStructure();
    fakeRegistryPortfolio(
        properties: [fdlPortfolioProperty(['latitude' => 55.25, 'longitude' => 12.17])],
        batchUsage: 'present',
    );
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties')
        ->call('loadEnrichment');

    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop['card']['streetview_url'] ?? null)->toBeNull();
});

it('omits the streetview URL when the property has no lat/lng even with the api key configured', function () {
    config(['metis.google_maps_api_key' => 'test-key-123']);

    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'present'); // no latitude/longitude
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties')
        ->call('loadEnrichment');

    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop['card']['streetview_url'] ?? null)->toBeNull();
});

it('maps property batch data into the full enrichment map: usage, latest sale date/price, valuation', function () {
    fakeRegistryStructure();
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => [
            'properties' => [fdlPortfolioProperty(['owner_cvr' => '44507781'])],
            'property_count' => 1, 'total_count' => 1,
        ]]]),
        '*/properties/batch*' => Http::response(['data' => [[
            'matrikel_id' => '2573669',
            'bbr' => ['buildings' => [['usage' => 130, 'total_area' => 150]]],
            'latest_transaction' => ['date' => '2022-06-01', 'price' => 1_200_000],
            'valuation' => ['estimated_value' => 1_400_000],
        ]]]),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties')
        ->call('loadEnrichment');

    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop['card']['usage'])->toBe('Bolig')
        ->and($prop['card']['latest_sale_date'])->toBe('2022-06-01')
        ->and($prop['card']['latest_sale_price'])->toBe(1_200_000)
        ->and($prop['card']['valuation'])->toBe(1_400_000);
});

it('regression: rehydrates enrichmentData across two separate requests (P0 pattern) — poll then expandNode', function () {
    // Request A: loadEnrichment() succeeds — enrichmentStatus becomes 'loaded'
    // and the company card appears in graphModel.
    fakeRegistryStructure();
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');

    $first = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadEnrichment');
    expect($first->get('enrichmentStatus'))->toBe('loaded');
    expect(collect($first->get('graphModel')['nodes'])->firstWhere('id', '44507781')['card'] ?? null)->not->toBeNull();

    // Request B: a FRESH component instance — protected $enrichmentData is
    // lost to hydration, but public $enrichmentStatus (hydrated normally,
    // forced here to simulate a real hydrated request) still says 'loaded'.
    // expandNode() must detect enrichmentData['companies'] === [] and refetch
    // (all sources cached) before rebuilding, or cards silently vanish.
    fakeRegistryStructure();
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');

    $second = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->set('enrichmentStatus', 'loaded')
        ->call('expandNode', 'sub:44018942');

    $nodes = collect($second->get('graphModel')['nodes']);
    expect($nodes->firstWhere('id', '44507781')['card'] ?? null)->not->toBeNull();
    expect($nodes->pluck('id'))->toContain('44027992'); // the expand itself still worked
});

it('regression: rehydrates enrichmentData across two separate requests (P0 pattern) — pollForUpdates completion', function () {
    $enrichmentCalls = 0;
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/44507781*' => Http::response(['data' => ['company' => [
            'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS',
            'financials' => [['year' => '2024', 'equity' => 1_000_000, 'assets' => 5_000_000, 'profit_loss' => 100_000]],
        ]]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => function () use (&$enrichmentCalls) {
            $enrichmentCalls++;

            // Request A's mount (call 1) sees 'completed' immediately (Request A
            // just establishes the enriched state via a direct loadEnrichment()
            // call, not via polling — so $enriching must be false for that call
            // to do real work). Request B's mount (call 2) sees 'running' so
            // $enriching=true and the explicit pollForUpdates call does real
            // work; pollForUpdates' own status check (call 3) sees 'completed'.
            return Http::response(['data' => ['status' => $enrichmentCalls === 2 ? 'running' : 'completed']]);
        },
    ]);

    // Request A: loadEnrichment() succeeds — enrichmentStatus becomes 'loaded'.
    $first = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadEnrichment');
    expect($first->get('enrichmentStatus'))->toBe('loaded');

    // Request B: a FRESH component instance — protected $enrichmentData lost to
    // hydration, public $enrichmentStatus forced to 'loaded' to simulate a real
    // hydrated request. pollForUpdates() completing must detect the mismatch
    // and refetch enrichment before rebuilding, or the company card vanishes.
    $second = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->set('enrichmentStatus', 'loaded')
        ->call('pollForUpdates');

    expect(collect($second->get('graphModel')['nodes'])->firstWhere('id', '44507781')['card'] ?? null)->not->toBeNull();
});
