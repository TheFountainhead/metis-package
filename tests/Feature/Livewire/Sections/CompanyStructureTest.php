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

    // graph wrapper: STABLE key — never re-mounted mid-pan. HASHED, because the
    // partial is shared with the CPR page where $query is the person's CPR and
    // a wire:key renders verbatim into the markup; the hash is equally stable
    // and equally unique, so the no-remount guarantee is untouched.
    expect($test->html())->toContain('wire:key="ownership-graph-'.sha1('20000001').'"');
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
// Opus-review fixes (F1-F6, 2026-07-26): pollForUpdates() now actually calls
// loadEnrichment() on completion (F1); loadEnrichment() gates on propertiesStatus
// being settled (F2) and on enrichmentStatus not already 'loaded' (F3);
// rehydrateBeforeRebuild() guards BOTH companies and properties halves of
// enrichmentData (F4); retryEnrichment() exists for the 'failed' state (F5);
// wire:key on the blade's x-init trigger includes propertiesStatus itself (F6a).
//
// Test-discipline fix (reviewer's main complaint): the P0 regression tests
// below no longer shortcut via ->set('enrichmentStatus', 'loaded') — they
// reach 'loaded' via the REAL path (mount → loadProperties(), which now
// triggers loadEnrichment() itself per F2).

it('loadEnrichment attaches company card/signals to graph nodes (via the real properties → enrichment path)', function () {
    // Http::fake() matches the FIRST-registered pattern (see the pollForUpdates
    // P0 test's comment above) — the specific per-cvr fakes must be registered
    // BEFORE fakeRegistryStructure()'s generic '*cvr/company/*' catch-all, or
    // the wildcard shadows them and every cvr gets the bare fallback fixture.
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty(['owner_cvr' => '44507781'])], batchUsage: 'present');

    // Real path: mount (propertiesStatus starts 'pending', so loadEnrichment()
    // would gate/no-op if called directly here) → loadProperties() settles
    // propertiesStatus to 'loaded' AND itself triggers loadEnrichment() (F2).
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded')
        ->and($c->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    // fakeRegistryCompanyInfo()'s financials fixture has no source=pdf, so it
    // is an API row carrying HELE KRONER (see CompanyStructure::
    // companyEnrichmentFromInfo()'s KONTRAKT docblock) — the card passes the
    // fixture's 1_000_000 kr. through unchanged.
    expect($node['card'] ?? null)->not->toBeNull()
        ->and($node['card']['equity'])->toBe(1_000_000)
        ->and($node['card']['fiscal_year'])->toBe('2024')
        ->and($node['card']['website'])->toBe('kirketorvet.dk')
        ->and($node['card']['industry'])->toBe('Ejendomshandel')
        ->and($node['card']['founded_date'])->toBe('2010-01-01')
        ->and($node['card']['employees'])->toBe(4);
});

/**
 * F3 fix: the enrichment cvr collection must only send ENRICHABLE_KINDS cvrs
 * to fetchCompanyInfosPooled() — an 'other' orphan-parent stub (synthesised
 * when a parent_of_cvr was pruned upstream) has a cvr but is a placeholder,
 * not a company the user asked to see enriched. Before this fix, the pool
 * call would fire a request for the stub's cvr too (wasted request against
 * registry-api for a node that never renders a card either way).
 *
 * 🚨 ASSERTED ON THE RESOLVED CVR LIST, not on Http::assertNotSent. Probed
 * during Task 8's extraction: Http::pool() requests that match NO fake stub
 * are not recorded at all, so the assertNotSent this test used to rely on
 * passed just as happily with the kind-gate REMOVED — the stub's cvr went
 * into the pool and simply left no trace to assert against. Mutation-tested
 * both ways after the rewrite: dropping the gate now fails here.
 */
it('excludes an "other" orphan-parent stub cvr from the enrichment pool call (F3)', function () {
    fakeRegistryCompanyInfo('11111111');
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0, 'parent_of_cvr' => '99999999'],
            ],
            'subsidiaries' => [],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('enrichmentStatus'))->toBe('loaded');

    $stub = collect($c->get('graphModel')['nodes'])->firstWhere('id', '99999999');
    expect($stub['kind'])->toBe('other');

    // The cvrs the component would pool, read off the same component state
    // fetchEnrichmentData() reads. 'searched' and 'legal' are enrichable;
    // the 'other' stub is not.
    $reflected = new ReflectionMethod($c->instance(), 'enrichmentCvrs');
    $reflected->setAccessible(true);
    $cvrs = $reflected->invoke($c->instance());

    expect($cvrs)->toContain('11111111')
        ->and($cvrs)->toContain('38653806')
        ->and($cvrs)->not->toContain('99999999');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/cvr/company/11111111'));
});

/**
 * F-A regression pin, v2 (prod-verificeret 2026-07-26): registry-api's
 * financials rows are SOURCE-dependent in unit — company-info.blade.php's
 * $toTdkk is the authoritative rule: rows with source=pdf carry t.DKK,
 * everything else (API) carries HELE KRONER. The frontend (ownership-graph
 * .js's fmtDKK()) assumes hele kroner throughout the enrichment/card chain,
 * so BOTH failure modes are pinned here: the original unconditional *1000
 * made API rows 1000× too high (Lars Horsbøl Holding's 92.438.600 kr.
 * equity rendered as "92.438,6 mio. kr." on prod), while no conversion made
 * pdf rows 1000× too low (2.527 t.DKK rendered as "3 tkr.").
 * companyEnrichmentFromInfo() converts at the SOURCE so every downstream
 * consumer (builder, Blade, JS) keeps assuming hele kroner unconditionally.
 */
it('passes API-sourced financials through as hele kroner (no *1000) — F-A 1000× regression pin', function () {
    fakeRegistryCompanyInfo('44507781', [
        'financials' => [
            ['year' => '2024', 'equity' => 92438600, 'assets' => 92901000, 'profit_loss' => 87545200],
        ],
    ]);
    fakeRegistryStructure();
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    expect($node['card']['equity'])->toBe(92_438_600)
        ->and($node['card']['result'])->toBe(87_545_200);
});

it('converts pdf-sourced financials from t.DKK to hele kroner (*1000) — F-A 1000× regression pin', function () {
    fakeRegistryCompanyInfo('44507781', [
        'financials' => [
            ['year' => '2024', 'equity' => 2527, 'assets' => 9000, 'profit_loss' => 316, 'source' => 'pdf'],
        ],
    ]);
    fakeRegistryStructure();
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    expect($node['card']['equity'])->toBe(2_527_000)
        ->and($node['card']['result'])->toBe(316_000);
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
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0, 'total_count' => 0]]]),
    ]);

    // Real path: propertiesStatus settles to 'empty' (no properties in the
    // fixture) — one of loadEnrichment()'s valid "settled" gate states (F2) —
    // and loadProperties() triggers loadEnrichment() itself.
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('empty')
        ->and($c->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    // Must resolve to the 2024 row (the actual latest year), not the first
    // or last array element. Fixture rows carry no source=pdf, so they are
    // API rows in hele kroner — passed through unchanged.
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

it('gates loadEnrichment on propertiesStatus being settled (F2 regression) — enrichment before properties land stays pending; enrichment after properties complete gets usage+streetview', function () {
    config(['metis.google_maps_api_key' => 'test-key-123']);

    fakeRegistryCompanyInfo('44507781');
    fakeRegistryStructure();
    fakeRegistryPortfolio(
        properties: [fdlPortfolioProperty(['owner_cvr' => '44507781', 'latitude' => 55.25, 'longitude' => 12.17])],
        batchUsage: 'present',
    );

    // propertiesStatus is 'pending' right after mount (wire:init="loadProperties"
    // hasn't fired yet in a Livewire::test() context — it only fires client-side).
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);
    expect($c->get('propertiesStatus'))->toBe('pending');

    // Calling loadEnrichment() directly BEFORE the property step has ever run
    // must stay 'pending' — this is the F2 regression: previously this call
    // would have "succeeded" with an empty properties map that nothing ever
    // repaired (loadProperties() never touched enrichmentData, and the
    // rehydration guard only fires once enrichmentStatus is ALREADY 'loaded').
    $c->call('loadEnrichment');
    expect($c->get('enrichmentStatus'))->toBe('pending');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/cvr/company/44507781'));

    // Now let the property step actually settle — loadProperties() itself
    // triggers loadEnrichment() once propertiesStatus lands on 'loaded' (F2).
    $c->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded')
        ->and($c->get('enrichmentStatus'))->toBe('loaded');

    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop['card']['usage'] ?? null)->toBe('Bolig')
        ->and($prop['card']['streetview_url'] ?? null)->not->toBeNull();
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
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0, 'total_count' => 0]]]),
    ]);

    // Real path: loadProperties() settles propertiesStatus ('empty', no
    // properties in the fixture) and itself triggers loadEnrichment() (F2).
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('enrichmentStatus'))->toBe('loaded');

    $ok = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    $failed = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44018942');
    expect($ok['card'] ?? null)->not->toBeNull();
    expect($failed['card'] ?? null)->toBeNull();
    expect($failed['signals'] ?? null)->toBeNull();
});

it('flips enrichmentStatus to failed when the whole pooled call throws, and retryEnrichment() recovers (F5)', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0, 'total_count' => 0]]]),
    ]);

    // Force fetchCompanyInfosPooled() to throw entirely (not per-cvr null) —
    // simulate a total outage by binding a RegistryApi double that throws
    // on the FIRST call, then succeeds (so retryEnrichment() can recover).
    $callCount = 0;
    $mock = Mockery::mock(\TheFountainhead\Metis\Services\RegistryApi::class)->makePartial();
    $mock->shouldReceive('fetchCompanyInfosPooled')->andReturnUsing(function ($cvrs) use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            throw new \RuntimeException('pool down');
        }

        return ['44507781' => ['cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'financials' => []]];
    });
    app()->instance(\TheFountainhead\Metis\Services\RegistryApi::class, $mock);

    // Real path: loadProperties() settles propertiesStatus and triggers
    // loadEnrichment() itself, which hits the throwing pool call.
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('enrichmentStatus'))->toBe('failed');

    // F5: retryEnrichment() resets to 'pending' then re-runs loadEnrichment(),
    // which now hits the SECOND (successful) mock response.
    $c->call('retryEnrichment');

    expect($c->get('enrichmentStatus'))->toBe('loaded');
});

it('loadEnrichment does not re-fetch once enrichmentStatus is already loaded (F3 idempotency) — repeated calls make exactly one HTTP round', function () {
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty(['owner_cvr' => '44507781'])], batchUsage: 'present');

    // loadProperties() already triggers loadEnrichment() once (F2) — this
    // establishes enrichmentStatus='loaded' via the real path.
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');
    expect($c->get('enrichmentStatus'))->toBe('loaded');

    $countBefore = count(\Illuminate\Support\Facades\Http::recorded());

    // Simulating the Blade's x-init trigger re-firing across a morph:
    // repeated direct calls must be pure no-ops — no new HTTP at all.
    $c->call('loadEnrichment');
    $c->call('loadEnrichment');

    expect(count(\Illuminate\Support\Facades\Http::recorded()))->toBe($countBefore);
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

    // loadProperties() alone now suffices — it triggers loadEnrichment() (F2).
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

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
        ->call('loadProperties');

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
        ->call('loadProperties');

    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop['card']['streetview_url'] ?? null)->toBeNull();
});

/**
 * F-B fix (multi-agent review): a non-numeric latitude (malformed upstream
 * registry-api row — e.g. empty string, or non-numeric junk) must not reach
 * the streetview URL builder's string interpolation. attachStreetviewUrls()
 * now guards with is_numeric() on BOTH lat and lng — this test pins the
 * non-numeric-lat half (the more common malformed-data shape: an empty
 * string, which is falsy but NOT null, so a `$lat === null` check alone
 * would have let it slip through before this fix).
 */
it('omits the streetview URL when latitude is non-numeric, even with lat/lng both "present"', function () {
    config(['metis.google_maps_api_key' => 'test-key-123']);

    fakeRegistryStructure();
    fakeRegistryPortfolio(
        properties: [fdlPortfolioProperty(['latitude' => 'not-a-number', 'longitude' => 12.17])],
        batchUsage: 'present',
    );
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

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
            // Verbatim shape from a real prod registry-api payload (read-only
            // curl verification, 2026-07-26) — including fields we don't
            // consume (transaction_type, registration_date, confidence), so a
            // future field rename/removal upstream is caught by this fixture
            // rather than silently drifting from what the API actually sends.
            'latest_transaction' => [
                'transaction_type' => 'sale',
                'transaction_date' => '2024-11-06',
                'registration_date' => '2024-11-06',
                'price' => 260000,
            ],
            'valuation' => [
                'estimated_value' => 534000,
                'valuation_date' => '2020-01-01',
                'confidence' => null,
            ],
        ]]]),
    ]);

    // loadProperties() alone suffices — triggers loadEnrichment() itself (F2).
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop['card']['usage'])->toBe('Bolig')
        ->and($prop['card']['latest_sale_date'])->toBe('2024-11-06')
        ->and($prop['card']['latest_sale_price'])->toBe(260000)
        ->and($prop['card']['valuation'])->toBe(534000);
});

it('pollForUpdates completing enrichment ($enriching flips false) itself calls loadEnrichment (F1 regression)', function () {
    // F1: this is the CORE bug the review found — pollForUpdates() flipping
    // $enriching to false previously never called loadEnrichment() at all,
    // so a company whose subsidiary-tree discovery was still 'running' at
    // mount would sit at enrichmentStatus='pending' forever.
    fakeRegistryCompanyInfo('44507781');
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'FDL-Invest ApS', 'owners' => []]]]),
        // mount sees 'running' (so $enriching=true); the explicit pollForUpdates
        // call sees 'completed'.
        '*enrichment*' => Http::sequence()
            ->push(['data' => ['status' => 'running']])
            ->push(['data' => ['status' => 'completed']]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => [
            'properties' => [fdlPortfolioProperty(['owner_cvr' => '44507781'])],
            'property_count' => 1, 'total_count' => 1,
        ]]]),
        '*/properties/batch*' => Http::response(['data' => []]),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);
    expect($c->get('enriching'))->toBeTrue();

    // The property step already settled independently of enrichment status
    // (loadProperties() is called via wire:init in real usage; call it
    // directly here) — propertiesStatus becomes 'loaded', satisfying F2's
    // OTHER gate, so once $enriching flips false below, loadEnrichment()
    // has nothing standing in its way except the completion call itself.
    $c->call('loadProperties');
    expect($c->get('propertiesStatus'))->toBe('loaded')
        // loadProperties()'s own trailing loadEnrichment() call gated on
        // $enriching still being true at this point — still 'pending'.
        ->and($c->get('enrichmentStatus'))->toBe('pending');

    $c->call('pollForUpdates');

    expect($c->get('enriching'))->toBeFalse()
        ->and($c->get('enrichmentStatus'))->toBe('loaded');

    $node = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    expect($node['card'] ?? null)->not->toBeNull();
});

it('rebuild() uses a real, test-observable "now" — dropping the now argument would make this test red (F6b)', function () {
    // Guards against a future regression where rebuild() stops passing `now`
    // to the builder (or passes a hardcoded/null value): freeze time so a
    // company founded exactly 1 month ago is deterministically "newly_founded".
    \Illuminate\Support\Facades\Date::setTestNow(\Carbon\CarbonImmutable::parse('2026-07-26'));

    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS', 'owners' => [], 'ancestors' => [], 'subsidiaries' => [[
                'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => [],
            ]],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => [
            'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS',
            // Founded 1 month before the frozen "now" — must resolve to
            // 'newly_founded' via a real Carbon::now() call inside rebuild(),
            // not a null/absent `now` (which would make the builder skip the
            // newly_founded check entirely — see OwnershipGraphBuilder's
            // companySignals() docblock).
            'founded_date' => '2026-06-26',
            'financials' => [],
        ]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0, 'total_count' => 0]]]),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    $node = collect($c->get('graphModel')['nodes'])->firstWhere('id', '44507781');
    expect($node['signals'] ?? [])->toContain('newly_founded');

    \Illuminate\Support\Facades\Date::setTestNow();
});

// ---- Review fix: rehydrateBeforeRebuild() guards BOTH halves of enrichmentData (F4) ----

it('regression: rehydrates enrichmentData across two separate requests via the REAL properties→enrichment path (P0 pattern) — poll then expandNode', function () {
    // Request A: the REAL path (loadProperties() → its own trailing
    // loadEnrichment() call, F2) — NOT a ->set('enrichmentStatus', 'loaded')
    // shortcut (reviewer's main complaint about the previous version of this
    // test: a shortcut proves nothing about whether the real trigger chain
    // actually reaches 'loaded').
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty(['owner_cvr' => '44507781'])], batchUsage: 'present');
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');

    $first = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');
    expect($first->get('enrichmentStatus'))->toBe('loaded');
    expect(collect($first->get('graphModel')['nodes'])->firstWhere('id', '44507781')['card'] ?? null)->not->toBeNull();
    $propNode = collect($first->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($propNode['card']['usage'] ?? null)->not->toBeNull();

    // Request B: a FRESH component instance — protected $enrichmentData is
    // lost to hydration, but public $enrichmentStatus/$propertiesStatus
    // (hydrated normally, forced here to simulate a real hydrated request)
    // still say 'loaded'. expandNode() must detect enrichmentData['companies']
    // AND ['properties'] both being empty (F4) and refetch (all sources
    // cached) before rebuilding, or cards silently vanish.
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty(['owner_cvr' => '44507781'])], batchUsage: 'present');
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryCompanyInfo('44018942');
    fakeRegistryCompanyInfo('44027992');

    $second = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->set('propertiesStatus', 'loaded')
        ->set('enrichmentStatus', 'loaded')
        ->call('expandNode', 'sub:44018942');

    $nodes = collect($second->get('graphModel')['nodes']);
    expect($nodes->firstWhere('id', '44507781')['card'] ?? null)->not->toBeNull();
    expect($nodes->pluck('id'))->toContain('44027992'); // the expand itself still worked
    $propNode2 = $nodes->firstWhere('kind', 'property');
    expect($propNode2['card']['usage'] ?? null)->not->toBeNull(); // F4: properties half rehydrated too
});

it('regression: rehydrates enrichmentData across two separate requests via the REAL properties→enrichment path (P0 pattern) — pollForUpdates completion', function () {
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
            // just establishes the enriched state via the REAL loadProperties()
            // path, not via polling — so $enriching must be false for that call
            // to do real work). Request B's mount (call 2) sees 'running' so
            // $enriching=true and the explicit pollForUpdates call does real
            // work; pollForUpdates' own status check (call 3) sees 'completed'.
            return Http::response(['data' => ['status' => $enrichmentCalls === 2 ? 'running' : 'completed']]);
        },
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => [
            'properties' => [fdlPortfolioProperty(['owner_cvr' => '44507781'])],
            'property_count' => 1, 'total_count' => 1,
        ]]]),
        '*/properties/batch*' => Http::response(['data' => []]),
    ]);

    // Request A: the REAL path — mount (enrichment already 'completed', so
    // $enriching=false) → loadProperties() settles propertiesStatus AND
    // triggers loadEnrichment() itself (F2), reaching 'loaded' without any
    // ->set() shortcut.
    $first = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');
    expect($first->get('enrichmentStatus'))->toBe('loaded');

    // Request B: a FRESH component instance — protected $enrichmentData lost to
    // hydration, public $enrichmentStatus/$propertiesStatus forced to 'loaded'
    // to simulate a real hydrated request (mount alone can't reach 'loaded'
    // properties synchronously — that's wire:init's job in the real page).
    // pollForUpdates() completing must detect the mismatch and refetch
    // enrichment before rebuilding (F1 + F4), or the company card vanishes.
    $second = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->set('propertiesStatus', 'loaded')
        ->set('enrichmentStatus', 'loaded')
        ->call('pollForUpdates');

    expect(collect($second->get('graphModel')['nodes'])->firstWhere('id', '44507781')['card'] ?? null)->not->toBeNull();
});

// ---- Opus re-review fix: F2+F3 interaction — retryProperties() must reopen enrichment ----

it('retryProperties() reopens enrichment after a failed-then-succeeded portfolio (F2+F3 interaction regression)', function () {
    // Real sequence the re-review reproduced: loadProperties() → portfolio 500
    // → propertiesStatus='failed' → loadProperties()'s trailing loadEnrichment()
    // runs ('failed' counts as settled, F2) and enriches against EMPTY
    // propertyData → enrichmentStatus='loaded' with no property cards. User
    // clicks "Prøv igen" → retryProperties() → portfolio succeeds this time →
    // propertyData is now populated, but WITHOUT resetting enrichmentStatus,
    // loadProperties()'s trailing loadEnrichment() call would be blocked by
    // F3's already-loaded gate forever — enrichmentData['properties'] staying
    // permanently empty (F4's rehydration guard never even runs: it lives
    // INSIDE loadEnrichment(), which F3 returns from before reaching it).
    fakeRegistryCompanyInfo('44507781');
    fakeRegistryStructure();

    Http::fake([
        '*/property-portfolio*' => Http::sequence()
            ->push(null, 500)
            ->push(['data' => ['portfolio' => [
                'properties' => [fdlPortfolioProperty(['owner_cvr' => '44507781', 'latitude' => 55.25, 'longitude' => 12.17])],
                'property_count' => 1, 'total_count' => 1,
            ]]]),
        '*/properties/batch*' => Http::response(['data' => [[
            'matrikel_id' => '2573669',
            'bbr' => ['buildings' => [['usage' => 130, 'total_area' => 150]]],
        ]]]),
    ]);
    config(['metis.google_maps_api_key' => 'test-key-123']);

    // First loadProperties() call: portfolio 500s → 'failed' → trailing
    // loadEnrichment() runs (settled) and reaches 'loaded' against an empty
    // property list — no property node/card exists yet at all.
    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('failed')
        ->and($c->get('enrichmentStatus'))->toBe('loaded');
    expect(collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->toBeNull();

    // retryProperties(): portfolio now succeeds (second push in the sequence).
    // Without the fix, enrichmentStatus stays 'loaded' from the first run and
    // the trailing loadEnrichment() call is a permanent no-op — the property
    // node would appear (from the builder's properties['list'] pass) but
    // WITHOUT its usage/streetview card, since enrichmentData['properties']
    // was never re-fetched.
    $c->call('retryProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded')
        ->and($c->get('enrichmentStatus'))->toBe('loaded');

    $prop = collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property');
    expect($prop)->not->toBeNull()
        ->and($prop['card']['usage'] ?? null)->toBe('Bolig')
        ->and($prop['card']['streetview_url'] ?? null)->not->toBeNull();
});

// ---- Task 2 (chips A): filter-chips på selskabs-grafen ----------------------
// Mirrors PersonStructure's proven chip pattern 1:1 (toggleLayer's never-empty
// rule, the $otherCount affordance comment, wire:loading.attr + wire:target),
// with the two divergences spec v2 makes binding for the COMPANY graph:
// P1-4 (enrichment scope resolves against an ALL-LAYERS graph, never $layers)
// and P1-6 (the blade lock is a server-computed layerContributesNodes(), not a
// badge count — on this graph the depth-cap/auto-expand/total-cap make pre-cap
// badges and actual node contribution diverge far more than on the person side).

/** The single <button> element for one company chip, sliced out of the HTML. */
function companyChipMarkupFor(string $html, string $layer): string
{
    $start = strpos($html, "toggleLayer('{$layer}')");

    expect($start)->not->toBeFalse("chip for layer [{$layer}] not rendered");

    $open = strrpos(substr($html, 0, $start), '<button');
    $close = strpos($html, '</button>', $start);

    return substr($html, $open, $close - $open);
}

it('toggles the owners layer off and back on, rebuilding the graph each time', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [
                ['cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => []],
            ],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    expect($c->get('layers'))->toBe(['owners', 'subsidiaries', 'properties']);

    $c->call('toggleLayer', 'owners');

    expect($c->get('layers'))->toBe(['subsidiaries', 'properties'])
        ->and(collect($c->get('graphModel')['nodes'])->pluck('id'))->not->toContain('11111111')
        ->and(collect($c->get('graphModel')['nodes'])->pluck('id'))->toContain('44507781');
    $c->assertDispatched('graph-refit');

    $c->call('toggleLayer', 'owners');

    expect($c->get('layers'))->toBe(['subsidiaries', 'properties', 'owners'])
        ->and(collect($c->get('graphModel')['nodes'])->pluck('id'))->toContain('11111111');
});

it('refuses a layer toggle that would leave nothing but the searched company', function () {
    // A company with ONLY owners: switching the empty subsidiaries/properties
    // chips off removes nothing and is always allowed; switching the owners
    // chip off would leave the searched root alone, so the server refuses it
    // outright and leaves $layers untouched.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    $c->call('toggleLayer', 'subsidiaries');
    expect($c->get('layers'))->toBe(['owners', 'properties']);

    $c->call('toggleLayer', 'properties');
    expect($c->get('layers'))->toBe(['owners']);

    // Owners now carries every node but the root — rejected, state untouched.
    $c->call('toggleLayer', 'owners');
    expect($c->get('layers'))->toBe(['owners'])
        ->and(collect($c->get('graphModel')['nodes'])->pluck('id'))->toContain('11111111');
});

it('locks the chip that carries every node and leaves the empty ones clickable', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    // Matched as the STANDALONE `disabled` attribute: every chip also carries
    // wire:loading.attr="disabled", which contains the word but is not the lock.
    $owners = companyChipMarkupFor($c->html(), 'owners');
    expect($owners)->toMatch('/\sdisabled(?=[\s>])/')
        ->and($owners)->toContain('mgraph-chip--locked');

    foreach (['subsidiaries', 'properties'] as $layer) {
        $chip = companyChipMarkupFor($c->html(), $layer);
        expect($chip)->not->toMatch('/\sdisabled(?=[\s>])/')
            ->and($chip)->not->toContain('mgraph-chip--locked');
    }
});

it('leaves a properties chip deselectable when the total-cap ate every property node, even though K > 0', function () {
    // 🚨 spec P1-6, the reason the lock is layerContributesNodes() and not the
    // badge. TWO caps compose here, which is exactly the divergence the person
    // graph never shows: properties_per_company (6) already trims 130 portfolio
    // rows to 6 nodes, and then a 130-strong subsidiary tree pushes the graph
    // past total_nodes (120) — whose FIRST truncation pass is property nodes.
    // Result: the properties layer contributes ZERO drawn nodes while K reads
    // 130. A lock computed from the badge would freeze a chip whose toggle
    // removes nothing.
    $properties = collect(range(1, 130))
        ->map(fn ($i) => fdlPortfolioProperty([
            'owner_cvr' => '38653806',
            'matrikel_id' => (string) (3000000 + $i),
            'address' => "Vej {$i}",
        ]))->all();

    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [],
            'subsidiaries' => collect(range(1, 130))
                ->map(fn ($i) => ['cvr' => (string) (50000000 + $i), 'name' => "Datter {$i}", 'ownership_share' => 100.0, 'children' => []])
                ->all(),
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio(properties: $properties);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    // Badge is PRE-cap: it answers "how many properties does this company own".
    expect($c->get('propertyCount'))->toBe(130);

    // …but not one property node survived the total-cap.
    expect(collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->toBeNull();

    // So the chip must be free — and the server agrees when clicked.
    $chip = companyChipMarkupFor($c->html(), 'properties');
    expect($chip)->not->toMatch('/\sdisabled(?=[\s>])/')
        ->and($chip)->not->toContain('mgraph-chip--locked');

    // 🚨 THE OTHER HALF, and the reason the lock asks "would this EMPTY the
    // graph" rather than "does this layer contribute nodes". Subsidiaries is
    // the only layer with drawn nodes right now, so a contribution-based lock
    // disabled this chip — wrongly: switching it off FREES the total-cap
    // budget, the 130 properties refill the graph, and toggleLayer() accepts
    // the click. A disabled button over an accepted click is the same
    // dead-button failure in mirror image.
    $subsidiaries = companyChipMarkupFor($c->html(), 'subsidiaries');
    expect($subsidiaries)->not->toMatch('/\sdisabled(?=[\s>])/')
        ->and($subsidiaries)->not->toContain('mgraph-chip--locked');

    $c->call('toggleLayer', 'subsidiaries');
    expect($c->get('layers'))->not->toContain('subsidiaries')
        // The properties the cap had eaten are now drawn — the graph refilled.
        ->and(collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->not->toBeNull();

    // And with subsidiaries gone, properties is now the sole carrier: the same
    // chip that was free a moment ago is refused, and rendered locked.
    expect(companyChipMarkupFor($c->html(), 'properties'))->toContain('mgraph-chip--locked');

    $c->call('toggleLayer', 'properties');
    expect($c->get('layers'))->toContain('properties');
});

it('counts chip badges pre-cap: owners = ancestor rows, subsidiaries = every descendant, properties = portfolio length', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            // 🚨 N is ancestor ROWS, not owner NODES (spec P3-1) — and here the
            // two genuinely differ: the second row names a parent_of_cvr that
            // is not itself an ancestor row, so the builder synthesises an
            // extra 'other' stub node to keep the chain connected. Rows = 2,
            // owner-ish nodes drawn = 3. Counting nodes would make the badge
            // claim an owner the register never listed.
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 60.0],
                ['person_name' => 'Anden Holding ApS', 'is_company' => true, 'cvr' => '22222222', 'ownership_share' => 40.0, 'parent_of_cvr' => '99999999'],
            ],
            // Recursive: 1 child + 1 grandchild + 1 great-grandchild = 3, NOT 1.
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
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio(properties: [
        fdlPortfolioProperty(['owner_cvr' => '38653806', 'matrikel_id' => '1']),
        fdlPortfolioProperty(['owner_cvr' => '38653806', 'matrikel_id' => '2']),
    ]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('ownerCount'))->toBe(2)
        ->and($c->get('subsidiaryCount'))->toBe(3)
        ->and($c->get('propertyCount'))->toBe(2);

    // The stub really is in the graph — so a node-derived N would read 3 here
    // and this test would be pinning nothing.
    expect(collect($c->get('graphModel')['nodes'])->firstWhere('id', '99999999')['kind'])->toBe('other');

    $html = $c->html();
    expect(companyChipMarkupFor($html, 'owners'))->toContain('(2)')
        ->and(companyChipMarkupFor($html, 'subsidiaries'))->toContain('(3)')
        ->and(companyChipMarkupFor($html, 'properties'))->toContain('(2)');
});

it('keeps the enrichment scope on ALL layers so a chip-off never freezes owners out of their cards (spec P1-4)', function () {
    // 🚨 THE F3-GATE FREEZE. enrichmentStatus === 'loaded' is permanent (only
    // retryEnrichment/retryProperties reopen it), so whatever scope the single
    // enrichment pass resolved is the scope FOREVER. Resolved against
    // $this->layers, a user who switched owners off before enrichment ran would
    // have owner nodes with no card for the rest of the page's life — switching
    // the chip back on returns the nodes but nothing ever fetches their cards.
    //
    // MUTATION-SENSITIVE: swap buildGraph(ALL_LAYERS) for $this->layers in the
    // enrichment scope path and this goes red at the final card assertion.
    fakeRegistryCompanyInfo('11111111');
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [
                ['cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => []],
            ],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    // Owners OFF, then enrichment runs (the real path: loadProperties settles
    // propertiesStatus and calls loadEnrichment itself).
    $c->call('toggleLayer', 'owners');
    expect(collect($c->get('graphModel')['nodes'])->pluck('id'))->not->toContain('11111111');

    $c->call('loadProperties');
    expect($c->get('enrichmentStatus'))->toBe('loaded');

    // Owners back ON — the node returns AND carries its card, because the
    // enrichment pass resolved its scope against an all-layers graph.
    $c->call('toggleLayer', 'owners');

    $owner = collect($c->get('graphModel')['nodes'])->firstWhere('id', '11111111');
    expect($owner)->not->toBeNull()
        ->and($owner['card'] ?? null)->not->toBeNull()
        ->and($owner['card']['equity'])->toBe(1_000_000);
});

it('keeps fetching a hidden layers data — chips filter the BUILD, never the fetch', function () {
    // Established rule (spec §A "Hentning fortsætter uanset chips"): the
    // portfolio call goes out with the properties chip off, so re-enabling the
    // chip shows already-fetched data instantly.
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty(['owner_cvr' => '38653806'])]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    $c->call('toggleLayer', 'properties');
    $c->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'property-portfolio'));

    // Hidden from the drawing…
    expect(collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->toBeNull();

    // …and instantly back when the chip returns, with no second portfolio call.
    $before = count(Http::recorded(fn ($request) => str_contains($request->url(), 'property-portfolio')));
    $c->call('toggleLayer', 'properties');

    expect(collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->not->toBeNull()
        ->and(count(Http::recorded(fn ($request) => str_contains($request->url(), 'property-portfolio'))))->toBe($before);
});

it('carries $layers across a rehydrated request so a toggled-off layer stays off', function () {
    // An owner is needed alongside the subsidiaries: with subsidiaries the ONLY
    // layer carrying nodes, toggleLayer() would (correctly) refuse the click and
    // this test would pass for the wrong reason.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [
                ['cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => []],
            ],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('toggleLayer', 'subsidiaries');

    expect($c->get('layers'))->not->toContain('subsidiaries');

    // A later request on the SAME public state (an expand) must keep building
    // without the layer — $layers is public, so it survives hydration.
    $c->call('expandNode', 'props:38653806');

    expect($c->get('layers'))->not->toContain('subsidiaries')
        ->and(collect($c->get('graphModel')['nodes'])->pluck('id'))->not->toContain('44507781');
});

it('disables the layer chips only while a toggleLayer round-trip is in flight', function () {
    // 🚨 wire:target is load-bearing: this section polls, and an UNTARGETED
    // wire:loading would grey the chips out on every poll tick rather than only
    // during the toggle's own round-trip.
    $blade = file_get_contents(__DIR__.'/../../../../resources/views/livewire/sections/company-structure.blade.php');

    expect($blade)->toContain('wire:loading.attr="disabled"')
        ->and($blade)->toContain('wire:target="toggleLayer"');
});

it('ignores an unknown layer name outright', function () {
    fakeRegistryStructure();
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('toggleLayer', 'ejendomme');

    expect($c->get('layers'))->toBe(['owners', 'subsidiaries', 'properties']);
});

it('unlocks both chips when two layers hold the SAME deduped node — neither one alone carries the graph (P1-6: lock reads contribution, not badge)', function () {
    // 🚨 THE MUTATION-KILLING CASE for the blade lock. A company that is BOTH
    // an ancestor and a subsidiary (a genuine cross-holding) is drawn ONCE:
    // addAncestors runs first and claims the id, so whichever layer is switched
    // off, the other still supplies the node. Both badges read 1, and NEITHER
    // chip may lock — switching either one off removes nothing.
    //
    // A badge-based lock gets this exactly backwards: with `$count > 0` both
    // chips see the other's badge as zero contribution (each is the sole
    // "carrier" by badge arithmetic) and BOTH render locked — two dead buttons
    // over toggles the server happily accepts. Swap layerContributesNodes() for
    // $count in the blade and this test goes red on the disabled-attribute
    // assertions below.
    //
    // The never-empty rule still holds where it must: with owners already off,
    // switching subsidiaries off too WOULD empty the graph, and toggleLayer()
    // refuses that click (asserted at the end) — the guarantee lives in the
    // server's trial build, never in the affordance.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Krydsejet ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [
                ['cvr' => '11111111', 'name' => 'Krydsejet ApS', 'ownership_share' => 100.0, 'children' => []],
            ],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    // Both badges are 1 — pre-cap, per-layer, exactly as documented…
    expect($c->get('ownerCount'))->toBe(1)
        ->and($c->get('subsidiaryCount'))->toBe(1)
        // …while the graph holds one single node for that cvr.
        ->and(collect($c->get('graphModel')['nodes'])->where('id', '11111111'))->toHaveCount(1);

    // Server truth: removing EITHER layer alone changes nothing, because the
    // other still supplies the shared node.
    expect($c->instance()->layerContributesNodes('owners'))->toBeFalse()
        ->and($c->instance()->layerContributesNodes('subsidiaries'))->toBeFalse();

    // …so NEITHER chip may render locked.
    foreach (['owners', 'subsidiaries'] as $layer) {
        $chip = companyChipMarkupFor($c->html(), $layer);
        expect($chip)->not->toMatch('/\sdisabled(?=[\s>])/')
            ->and($chip)->not->toContain('mgraph-chip--locked');
    }

    // And the server accepts the click the affordance promised.
    $c->call('toggleLayer', 'owners');
    expect($c->get('layers'))->toBe(['subsidiaries', 'properties'])
        ->and(collect($c->get('graphModel')['nodes'])->pluck('id'))->toContain('11111111');

    // The never-empty rule still bites where it must: subsidiaries is now the
    // sole carrier, so this click IS refused — by the server's trial build.
    $c->call('toggleLayer', 'subsidiaries');
    expect($c->get('layers'))->toBe(['subsidiaries', 'properties']);
});

// ---- Task 2, fix-round: the lock must not fire without builder input --------

it('renders every chip UNLOCKED on a request that never rehydrated the structure (poll-path degrade)', function () {
    // 🚨 THE GAP 65 GREEN TESTS MISSED. Every chip test above renders on a
    // request that had just rebuilt from real $structureData — but the
    // subsidiary-discovery poll does NOT: pollForUpdates() early-returns while
    // $enriching is true, BEFORE any rehydrate, so the whole request runs with
    // $structureData === []. The chip container still renders (it reads the
    // PUBLIC $graphModel, which arrived over the wire), and every trial build
    // then comes back with just the searched root — so layerContributesNodes()
    // said "yes, removing this empties the graph" for all three, and the user
    // stared at three dead buttons, re-disabled every 3 seconds, for the entire
    // discovery phase.
    //
    // Degrade to UNLOCKED rather than rehydrating per poll: a wrongly-ENABLED
    // chip is caught by toggleLayer()'s own refusal (which rehydrates first),
    // while a wrongly-DISABLED chip has no recourse at all.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [
                ['cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => []],
            ],
        ]]),
        // 'running' ⇒ $enriching stays true ⇒ pollForUpdates() early-returns.
        '*enrichment*' => Http::response(['data' => ['status' => 'running']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);
    expect($c->get('enriching'))->toBeTrue();

    // The poll request: no rehydrate runs, so the protected builder input is
    // gone while the public graph model is still on screen.
    $c->call('pollForUpdates');

    $structureData = (function () {
        $p = new ReflectionProperty(CompanyStructure::class, 'structureData');
        $p->setAccessible(true);

        return $p->getValue($this);
    })->call($c->instance());

    expect($structureData)->toBe([])
        ->and(count($c->get('graphModel')['nodes']))->toBeGreaterThan(1);

    // Not one chip may be locked on this request.
    $html = $c->html();
    foreach (['owners', 'subsidiaries', 'properties'] as $layer) {
        $chip = companyChipMarkupFor($html, $layer);
        expect($chip)->not->toMatch('/\sdisabled(?=[\s>])/')
            ->and($chip)->not->toContain('mgraph-chip--locked');
    }
});

it('still enforces the never-empty rule on the server when a chip was rendered unlocked without structure state', function () {
    // The degrade's safety net: an ENABLED chip is only safe because
    // toggleLayer() rehydrates and then refuses. Pin that the refusal really
    // happens on the path the degrade opens up — otherwise "a wrongly-enabled
    // chip is caught by the server" is an assumption, not a guarantee.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'running']]),
    ]);
    fakeRegistryPortfolio();

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('pollForUpdates');

    // Owners is the sole carrier, and on this structure-less request the chip
    // renders enabled (the degrade) — but the click is still refused, because
    // toggleLayer() rehydrates before it evaluates the rule.
    expect(companyChipMarkupFor($c->html(), 'owners'))->not->toMatch('/\sdisabled(?=[\s>])/');

    $c->call('toggleLayer', 'owners');
    expect($c->get('layers'))->toContain('owners');
});

it('builds each distinct layer-set at most once per request (trial-build memo)', function () {
    // One render calls layerContributesNodes() three times, and the enrichment
    // paths add a fourth all-layers build — each one a FULL build over
    // pre-truncation inputs (the 130+130 fixture above is not hypothetical).
    // The memo makes a render cost one build per DISTINCT layer-set.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'FDL-Invest ApS',
            'owners' => [],
            'ancestors' => [
                ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0],
            ],
            'subsidiaries' => [
                ['cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => []],
            ],
        ]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);
    fakeRegistryPortfolio();

    // A builder that counts build() calls, bound for the whole request.
    $spy = new class extends TheFountainhead\Metis\Services\OwnershipGraphBuilder
    {
        public array $builtLayerSets = [];

        public function build(
            string $query,
            ?string $companyName,
            array $structure,
            array $properties,
            array $enrichment,
            array $expandedNodeIds,
            array $caps,
            ?Carbon\CarbonImmutable $now = null,
            array $layers = ['owners', 'subsidiaries', 'properties'],
        ): array {
            $this->builtLayerSets[] = $layers;

            return parent::build($query, $companyName, $structure, $properties, $enrichment, $expandedNodeIds, $caps, $now, $layers);
        }
    };
    app()->instance(TheFountainhead\Metis\Services\OwnershipGraphBuilder::class, $spy);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    $inst = $c->instance();
    $build = new ReflectionMethod($inst, 'buildGraph');
    $build->setAccessible(true);

    // Mount's own render already built FOUR distinct sets — the full set for
    // rebuild() plus one per chip — and after the memo those are the only four
    // builds the whole request pays for. Probed by key: the cache holds exactly
    // owners|properties|subsidiaries, properties|subsidiaries, owners|properties
    // and owners|subsidiaries.
    $memoKeys = function () {
        $p = new ReflectionProperty(CompanyStructure::class, 'trialBuildCache');
        $p->setAccessible(true);

        return array_map(fn ($k) => strstr($k, ':', true), array_keys($p->getValue($this)));
    };

    expect($memoKeys->call($inst))->toEqualCanonicalizing([
        'owners|properties|subsidiaries',
        'properties|subsidiaries',
        'owners|properties',
        'owners|subsidiaries',
    ]);

    // So re-asking every chip question costs NOTHING — the three trial builds a
    // naive implementation repeats on every single render.
    $spy->builtLayerSets = [];
    $inst->layerContributesNodes('owners');
    $inst->layerContributesNodes('subsidiaries');
    $inst->layerContributesNodes('properties');
    expect($spy->builtLayerSets)->toBe([]);

    // Order must not create a false miss: the key is the SORTED set, so asking
    // for a cached set the other way round is still a hit.
    $build->invoke($inst, ['properties', 'subsidiaries']);
    $build->invoke($inst, ['subsidiaries', 'properties']);
    expect($spy->builtLayerSets)->toBe([]);

    // A genuinely un-built set IS a miss — once, then cached.
    $build->invoke($inst, ['owners']);
    expect($spy->builtLayerSets)->toHaveCount(1);
    $build->invoke($inst, ['owners']);
    expect($spy->builtLayerSets)->toHaveCount(1);

    // 🚨 And a build after the INPUTS change is a miss too, not a stale hit —
    // the guarantee that makes the memo correct on loadProperties()/expandNode(),
    // which build early and then write new input in the same request.
    $spy->builtLayerSets = [];
    $build->invoke($inst, ['owners']);
    expect($spy->builtLayerSets)->toBe([]);

    $expanded = new ReflectionProperty(CompanyStructure::class, 'expandedNodeIds');
    $expanded->setAccessible(true);
    $expanded->setValue($inst, ['sub:44507781']);

    $build->invoke($inst, ['owners']);
    expect($spy->builtLayerSets)->toHaveCount(1);
});

it('never persists the trial-build memo across requests — a rebuild on new input is never served from cache', function () {
    // The memo is PROTECTED state, so hydration wipes it naturally. That is the
    // whole safety argument: within one request the inputs cannot change (every
    // mutation path rebuilds through buildGraph AFTER writing them), and across
    // requests there is no cache at all. Pinned so a later "optimisation" that
    // promotes it to public/session state fails here.
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty(['owner_cvr' => '44507781'])]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806']);

    $memo = (function () {
        $p = new ReflectionProperty(CompanyStructure::class, 'trialBuildCache');
        $p->setAccessible(true);

        return $p->getValue($this);
    });

    // The mount request warmed it.
    expect($memo->call($c->instance()))->not->toBe([]);

    // A later request starts with an EMPTY memo, and the property is not part
    // of the wire payload either.
    $c->call('loadProperties');

    expect($c->get('graphModel')['nodes'])->not->toBeEmpty();
    expect(collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->not->toBeNull();

    // Property nodes only appear if the post-loadProperties rebuild was NOT
    // served from the mount-time memo (which held a property-less graph).
});
