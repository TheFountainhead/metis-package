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

    // Subsidiaries still render as server-side HTML rows (org-chart trunk).
    // The owner now lives in the graph's @js payload (x-data), not a text row,
    // so assert against the mounted graph model instead of visible text.
    $test = Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSee('EGEN DATTER ApS');

    $graph = $test->instance()->ownershipGraphData();
    expect(collect($graph['nodes'])->pluck('label'))->toContain('EJER A/S');
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

    $labels = collect($test->instance()->ownershipGraphData()['nodes'])->pluck('label');
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

    $test = Livewire::test(CompanyStructure::class, ['query' => '70000001']);

    // The graph is JS-rendered from ownershipGraphData(), so "no double-render"
    // is now a model-level property: each owner is one node, not two. (Labels
    // appear in the x-data + carrier JSON payloads, which is not a visual render.)
    $g = $test->instance()->ownershipGraphData();
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

    $g = Livewire::test(CompanyStructure::class, ['query' => '70000001'])->instance()->ownershipGraphData();

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
    $nodes = collect($test->instance()->ownershipGraphData()['nodes']);

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

    $g = Livewire::test(CompanyStructure::class, ['query'=>'11111111'])->instance()->ownershipGraphData();

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

    $g = Livewire::test(CompanyStructure::class, ['query'=>'22222222'])->instance()->ownershipGraphData();

    // both persons survive as distinct nodes
    $persons = collect($g['nodes'])->where('label', 'Jens Hansen');
    expect($persons)->toHaveCount(2);
    // and both own the searched company (two distinct edges)
    $edges = collect($g['edges'])->where('to', 'searched');
    expect($edges)->toHaveCount(2);
});

it('feeds model changes via a hashed carrier while the graph keeps a stable key (P1 stale-graph, no mid-pan remount)', function () {
    // The graph wrapper has a STABLE wire:key (query only) so it is never
    // re-mounted mid-interaction. A separate carrier node, keyed on the model
    // hash, re-inits on each enrichment poll and dispatches the fresh model to
    // the graph (which re-lays-out in place). So: graph key stable, carrier key
    // = content hash, and the graph listens for graph-model-updated.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name'=>'OpCo','owners'=>[],'subsidiaries'=>[],
            'ancestors'=>[['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false]],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $test = Livewire::test(CompanyStructure::class, ['query'=>'20000001']);
    $html = $test->html();

    // hash still changes when the model grows (drives the carrier re-init)
    $shallow = $test->instance()->ownershipGraphData();
    $deep = ['nodes' => array_merge($shallow['nodes'], [['id'=>'99','label'=>'New Owner','cvr'=>'99','kind'=>'legal','share'=>50.0]]), 'edges' => $shallow['edges']];
    expect($test->instance()->graphKey($shallow))->not->toBe($test->instance()->graphKey($deep));

    // graph wrapper: STABLE key (query only) — never re-mounted mid-pan
    expect($html)->toContain('wire:key="ownership-graph-20000001"');
    // carrier: hashed key + dispatches the fresh model; graph listens for it
    expect($html)->toContain('wire:key="ownership-graph-feed-20000001-'.$test->instance()->graphKey($shallow).'"');
    expect($html)->toContain('graph-model-updated');
    expect($html)->toContain('refreshModel($event.detail)');
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
    $g = Livewire::test(CompanyStructure::class, ['query'=>'33333333'])->instance()->ownershipGraphData();

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

    $g = Livewire::test(CompanyStructure::class, ['query'=>'40000000'])->instance()->ownershipGraphData();

    // one node (already deduped) AND one edge (new dedup)
    expect(collect($g['nodes'])->where('id', '40000001')->count())->toBe(1);
    expect(collect($g['edges'])->where('from', '40000001')->where('to', 'searched')->count())->toBe(1);
});

it('keys the graph on topology only, so relabelling without a shape change does not churn the wire:key (P2 graphKey)', function () {
    // graphKey must change when the graph SHAPE grows (new node/edge), but not when
    // only a label/share changes — else a reorder or late companyName fetch re-mounts
    // the wire:ignore subtree and resets the user's zoom/pan mid-enrichment.
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name'=>'OpCo','owners'=>[],'subsidiaries'=>[],
            'ancestors'=>[['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false]],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $inst = Livewire::test(CompanyStructure::class, ['query'=>'20000001'])->instance();
    $graph = $inst->ownershipGraphData();

    // same topology, only a label + share differ → SAME key (no churn)
    $relabelled = $graph;
    $relabelled['nodes'][1]['label'] = 'HoldCo Renamed ApS';
    $relabelled['nodes'][1]['share'] = 99.0;
    expect($inst->graphKey($relabelled))->toBe($inst->graphKey($graph));

    // added node (shape change) → DIFFERENT key (re-mount + fresh layout)
    $grown = $graph;
    $grown['nodes'][] = ['id'=>'88','label'=>'New','cvr'=>'88','kind'=>'legal','share'=>10.0];
    $grown['edges'][] = ['from'=>'88','to'=>'20000002','label'=>'10 %'];
    expect($inst->graphKey($grown))->not->toBe($inst->graphKey($graph));
});
