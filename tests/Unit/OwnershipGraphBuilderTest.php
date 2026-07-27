<?php

use TheFountainhead\Metis\Services\OwnershipGraphBuilder;

function buildGraph(array $overrides = []): array
{
    $builder = new OwnershipGraphBuilder;

    return $builder->build(
        query: $overrides['query'] ?? '38653806',
        companyName: $overrides['companyName'] ?? 'FDL-Invest ApS',
        structure: $overrides['structure'] ?? ['ancestors' => [], 'subsidiaries' => []],
        properties: $overrides['properties'] ?? ['list' => [], 'usage' => []],
        enrichment: $overrides['enrichment'] ?? [],
        expandedNodeIds: $overrides['expandedNodeIds'] ?? [],
        caps: $overrides['caps'] ?? ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 120],
        now: $overrides['now'] ?? null,
    );
}

it('builds the searched node alone for empty input', function () {
    $g = buildGraph();

    expect($g['nodes'])->toHaveCount(1)
        ->and($g['nodes'][0]['id'])->toBe('searched')
        ->and($g['nodes'][0]['label'])->toBe('FDL-Invest ApS')
        ->and($g['edges'])->toBeEmpty();
});

it('builds ancestor nodes and edges to the searched node', function () {
    $g = buildGraph(['structure' => ['ancestors' => [
        ['person_name' => 'Frederik G D Larnæs', 'is_company' => false, 'cvr' => null, 'ownership_share' => 100.0, 'parent_of_cvr' => null],
    ], 'subsidiaries' => []]]);

    expect($g['nodes'])->toHaveCount(2)
        ->and($g['nodes'][1]['kind'])->toBe('person')
        ->and($g['edges'][0])->toMatchArray(['to' => 'searched', 'label' => '100 %']);
});

it('never collapses two same-named persons owning the same company', function () {
    $rows = [
        ['person_name' => 'Jens Hansen', 'is_company' => false, 'cvr' => null, 'ownership_share' => 50.0, 'parent_of_cvr' => null],
        ['person_name' => 'Jens Hansen', 'is_company' => false, 'cvr' => null, 'ownership_share' => 50.0, 'parent_of_cvr' => null],
    ];
    $g = buildGraph(['structure' => ['ancestors' => $rows, 'subsidiaries' => []]]);

    expect($g['nodes'])->toHaveCount(3)->and($g['edges'])->toHaveCount(2);
});

it('synthesises a stub node for a pruned parent cvr', function () {
    $g = buildGraph(['structure' => ['ancestors' => [
        ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0, 'parent_of_cvr' => '99999999'],
    ], 'subsidiaries' => []]]);

    $stub = collect($g['nodes'])->firstWhere('id', '99999999');
    expect($stub)->not->toBeNull()->and($stub['kind'])->toBe('other');
});

/**
 * F-C fix (multi-agent review): an unrecognised owner_kind on a COMPANY
 * ancestor row (e.g. a future/unexpected API value like 'ultimate') must
 * normalise to 'legal', not pass through raw. Before this fix, a raw
 * passthrough could (a) collide with 'other' — the distinct kind
 * addAncestors() synthesises for orphan-parent stubs just below, in the SAME
 * method — and (b) silently exclude a genuine company-ancestor from
 * ENRICHABLE_KINDS-gated enrichment purely because the API used a kind
 * string this builder didn't already recognise. This test asserts BOTH
 * halves: the node's kind is normalised to 'legal', AND it still receives
 * its company card via applyEnrichment() (proving ENRICHABLE_KINDS gate
 * passes for the normalised kind).
 */
it('normalises an unknown company owner_kind to legal and still enriches the node (F-C)', function () {
    $g = buildGraph([
        'structure' => ['ancestors' => [
            ['person_name' => 'Ultimate Holding ApS', 'is_company' => true, 'cvr' => '33333333', 'owner_kind' => 'ultimate', 'ownership_share' => 100.0, 'parent_of_cvr' => null],
        ], 'subsidiaries' => []],
        'enrichment' => ['companies' => ['33333333' => ['equity' => 5.0]], 'properties' => []],
    ]);

    $node = collect($g['nodes'])->firstWhere('id', '33333333');
    expect($node['kind'])->toBe('legal')
        ->and($node)->toHaveKey('card');
});

it('adds subsidiaries two levels deep with edges parent→child', function () {
    // Level-3 subtree enlarged to 4 hidden descendants (Task 9: ≤3 auto-
    // renders instead of signalling) so this fixture's ORIGINAL intent —
    // "a hidden third level stays hidden behind an expand signal" — still
    // holds after the auto-expand-lineær-kæder change.
    $subs = [[
        'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0,
        'children' => [[
            'cvr' => '44018942', 'name' => 'Trygve 1 ApS', 'ownership_share' => 100.0,
            'children' => [
                ['cvr' => '44027992', 'name' => 'Schneidereit Trygve 1 A/S', 'ownership_share' => 67.0, 'children' => []],
                ['cvr' => '44027993', 'name' => 'D', 'ownership_share' => 1.0, 'children' => []],
                ['cvr' => '44027994', 'name' => 'E', 'ownership_share' => 1.0, 'children' => []],
                ['cvr' => '44027995', 'name' => 'F', 'ownership_share' => 1.0, 'children' => []],
            ],
        ]],
    ]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs]]);

    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('44507781')->toContain('44018942')
        ->and($ids)->not->toContain('44027992')                       // level 3 truncated (depth cap 2)
        ->and(collect($g['edges'])->firstWhere('to', '44507781')['from'])->toBe('searched')
        ->and(collect($g['edges'])->firstWhere('to', '44018942')['from'])->toBe('44507781');

    $trygve1 = collect($g['nodes'])->firstWhere('id', '44018942');
    expect($trygve1['expand']['relations'])->toBe(4);                 // 4 hidden children signalled
});

it('marks subsidiary nodes with kind subsidiary and labels edges with the share', function () {
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => [
        ['cvr' => '45170209', 'name' => 'Inova ApS', 'ownership_share' => 100.0, 'children' => []],
    ]]]);

    $node = collect($g['nodes'])->firstWhere('id', '45170209');
    expect($node['kind'])->toBe('subsidiary')
        ->and(collect($g['edges'])->firstWhere('to', '45170209')['label'])->toBe('100 %');
});

it('dedups a subsidiary that is also an ancestor node', function () {
    $g = buildGraph(['structure' => [
        'ancestors' => [['person_name' => 'Loop ApS', 'is_company' => true, 'cvr' => '22222222', 'ownership_share' => 10.0, 'parent_of_cvr' => null]],
        'subsidiaries' => [['cvr' => '22222222', 'name' => 'Loop ApS', 'ownership_share' => 5.0, 'children' => []]],
    ]]);

    expect(collect($g['nodes'])->where('id', '22222222'))->toHaveCount(1);
});

it('renders a hidden third level when its parent is expanded', function () {
    $subs = [['cvr' => '44507781', 'name' => 'A', 'ownership_share' => 50.0, 'children' => [
        ['cvr' => '44018942', 'name' => 'B', 'ownership_share' => 100.0, 'children' => [
            ['cvr' => '44027992', 'name' => 'C', 'ownership_share' => 67.0, 'children' => []],
        ]],
    ]]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'expandedNodeIds' => ['sub:44018942']]);

    expect(collect($g['nodes'])->pluck('id'))->toContain('44027992');
});

it('is idempotent: duplicate expand ids change nothing', function () {
    $subs = [['cvr' => '44507781', 'name' => 'A', 'ownership_share' => 50.0, 'children' => [
        ['cvr' => '44018942', 'name' => 'B', 'ownership_share' => 100.0, 'children' => []],
    ]]];
    $once = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'expandedNodeIds' => ['sub:44507781']]);
    $twice = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'expandedNodeIds' => ['sub:44507781', 'sub:44507781']]);

    expect($twice)->toEqual($once);
});

it('auto-renders a linear chain of 2 hidden nodes fully instead of an expand button (Task 9)', function () {
    // Root at depth 1 (cap 2) → Mid at depth 2 (cap boundary) → Leaf at
    // depth 3, hidden. Leaf's hidden subtree is just Leaf itself: 1 node
    // — well under the ≤3 threshold. A SECOND single-child level is added
    // (Leaf → Grandleaf) to make this a genuine 2-node LINEAR CHAIN, per
    // brief Step 1(a): "lineær kæde (2 skjulte noder) → begge renderes,
    // INGEN expand-knap på forælderen."
    $subs = [[
        'cvr' => '10000001', 'name' => 'Root', 'ownership_share' => 100.0,
        'children' => [[
            'cvr' => '10000002', 'name' => 'Mid', 'ownership_share' => 100.0,
            'children' => [[
                'cvr' => '10000003', 'name' => 'Leaf', 'ownership_share' => 100.0,
                'children' => [[
                    'cvr' => '10000004', 'name' => 'Grandleaf', 'ownership_share' => 100.0, 'children' => [],
                ]],
            ]],
        ]],
    ]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs]]);

    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('10000003')->toContain('10000004');

    $mid = collect($g['nodes'])->firstWhere('id', '10000002');
    expect($mid['expand'])->toBeNull();
});

it('renders the RS HoldCo linear chain to Resights fully on the FIRST build, no expandedNodeIds needed (Lars Horsbøl case)', function () {
    // 5% → 100% → 100%: searched → RS HoldCo (depth 1) → RS BidCo (depth 2,
    // cap boundary) → Resights (depth 3, hidden, 1-node subtree). This is
    // the exact production UX gap that motivated Task 9 (Resights sat two
    // expand-clicks deep). Resights must be visible WITHOUT the caller ever
    // expanding anything.
    $subs = [[
        'cvr' => '45429075', 'name' => 'RS HoldCo', 'ownership_share' => 5.0,
        'children' => [[
            'cvr' => '45429334', 'name' => 'RS BidCo', 'ownership_share' => 100.0,
            'children' => [[
                'cvr' => '41527080', 'name' => 'Resights', 'ownership_share' => 100.0, 'children' => [],
            ]],
        ]],
    ]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs]]);

    expect(collect($g['nodes'])->pluck('id'))->toContain('41527080');

    $bidco = collect($g['nodes'])->firstWhere('id', '45429334');
    expect($bidco['expand'])->toBeNull();
});

it('keeps the expand button unchanged for a hidden subtree of 4 nodes (Task 9 boundary)', function () {
    // 4 hidden descendants (one direct child with 3 of its own) is JUST over
    // the ≤3 threshold — the parent must keep the ordinary expand.relations
    // signal, none of the descendants auto-render.
    $subs = [[
        'cvr' => '20000001', 'name' => 'Root', 'ownership_share' => 100.0,
        'children' => [[
            'cvr' => '20000002', 'name' => 'Mid', 'ownership_share' => 100.0,
            'children' => [[
                'cvr' => '20000003', 'name' => 'Hidden1', 'ownership_share' => 100.0, 'children' => [
                    ['cvr' => '20000004', 'name' => 'Hidden2', 'ownership_share' => 1.0, 'children' => []],
                    ['cvr' => '20000005', 'name' => 'Hidden3', 'ownership_share' => 1.0, 'children' => []],
                    ['cvr' => '20000006', 'name' => 'Hidden4', 'ownership_share' => 1.0, 'children' => []],
                ],
            ]],
        ]],
    ]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs]]);

    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->not->toContain('20000003')
        ->not->toContain('20000004')->not->toContain('20000005')->not->toContain('20000006');

    // expand.relations signals DIRECT hidden children only (unchanged
    // semantics) — Mid has exactly 1 direct child (Hidden1); the 4-node
    // count is the SUBTREE size that decided auto-expand did NOT trigger.
    $mid = collect($g['nodes'])->firstWhere('id', '20000002');
    expect($mid['expand']['relations'])->toBe(1);
});

it('total-cap-truncates an auto-expanded node with correct capped-signal, per the normal removeNode rules (Task 9)', function () {
    // Root's hidden 2-node chain (Mid2 → Leaf) auto-renders because it is
    // ≤3, but a TIGHT total_nodes cap still forces truncateToCap to cut the
    // deepest layer — auto-expanded nodes are ordinary rendered nodes and
    // must remain subject to the total cap exactly like any other, folding
    // onto the parent with capped_relations: true (not resolvable via
    // expandNode, since there was never an expand click to replay).
    $subs = [[
        'cvr' => '30000001', 'name' => 'Root', 'ownership_share' => 100.0,
        'children' => [[
            'cvr' => '30000002', 'name' => 'Mid', 'ownership_share' => 100.0,
            'children' => [[
                'cvr' => '30000003', 'name' => 'Leaf', 'ownership_share' => 100.0, 'children' => [],
            ]],
        ]],
    ]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'caps' => ['subsidiary_depth' => 1, 'properties_per_company' => 6, 'total_nodes' => 3],
    ]);

    // searched + Root + Mid = cap 3; Leaf (the deepest, auto-expanded layer)
    // must be the one truncated.
    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('30000002')->not->toContain('30000003');

    $mid = collect($g['nodes'])->firstWhere('id', '30000002');
    expect($mid['expand']['relations'])->toBe(1)
        ->and($mid['expand']['capped_relations'])->toBeTrue();

    $nodeIds = collect($g['nodes'])->pluck('id')->all();
    foreach ($g['edges'] as $edge) {
        expect($nodeIds)->toContain($edge['from'])->toContain($edge['to']);
    }
});

it('is deterministic: auto-expanding a linear chain produces identical output across builds (Task 9)', function () {
    $subs = [[
        'cvr' => '45429075', 'name' => 'RS HoldCo', 'ownership_share' => 5.0,
        'children' => [[
            'cvr' => '45429334', 'name' => 'RS BidCo', 'ownership_share' => 100.0,
            'children' => [[
                'cvr' => '41527080', 'name' => 'Resights', 'ownership_share' => 100.0, 'children' => [],
            ]],
        ]],
    ]];
    $args = ['structure' => ['ancestors' => [], 'subsidiaries' => $subs]];

    expect(buildGraph($args))->toEqual(buildGraph($args));
});

function fdlProperty(array $overrides = []): array
{
    return array_merge([
        'owner_cvr' => '44507781', 'matrikel_id' => '2573669', 'is_matriculated' => true,
        'address' => 'Kongshøjvej 2', 'city' => 'Store Heddinge', 'valuation' => 534000, 'depth' => 1,
    ], $overrides);
}

it('hangs property nodes on their owning company with bfe: ids', function () {
    $subs = [['cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => []]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => [fdlProperty()], 'usage' => ['2573669' => 'Fritliggende enfamiliehus']],
    ]);

    $prop = collect($g['nodes'])->firstWhere('id', 'bfe:2573669');
    expect($prop)->not->toBeNull()
        ->and($prop['kind'])->toBe('property')
        ->and($prop['label'])->toBe('Kongshøjvej 2')
        ->and($prop['meta'])->toMatchArray(['bfe' => '2573669', 'usage' => 'Fritliggende enfamiliehus'])
        ->and(collect($g['edges'])->firstWhere('to', 'bfe:2573669')['from'])->toBe('44507781');
});

it('drops properties whose owner is not in the graph', function () {
    $g = buildGraph(['properties' => ['list' => [fdlProperty(['owner_cvr' => '99999999'])], 'usage' => []]]);

    expect(collect($g['nodes'])->pluck('id'))->not->toContain('bfe:2573669');
});

it('caps properties per company at 6 and signals the rest via expand.properties', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $props = collect(range(1, 9))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (1000000 + $i), 'address' => 'Vej '.$i]))->all();
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => $props, 'usage' => []]]);

    expect(collect($g['nodes'])->where('kind', 'property'))->toHaveCount(6)
        ->and(collect($g['nodes'])->firstWhere('id', '44507781')['expand']['properties'])->toBe(3);
});

it('lifts the property cap for a props:-expanded company', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $props = collect(range(1, 9))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (1000000 + $i)]))->all();
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => $props, 'usage' => []],
        'expandedNodeIds' => ['props:44507781'],
    ]);

    expect(collect($g['nodes'])->where('kind', 'property'))->toHaveCount(9);
});

it('dedups a property owned by two graph companies into one node with two edges', function () {
    $subs = [
        ['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []],
        ['cvr' => '45170209', 'name' => 'I', 'ownership_share' => 100.0, 'children' => []],
    ];
    $props = [fdlProperty(), fdlProperty(['owner_cvr' => '45170209'])];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => $props, 'usage' => []]]);

    expect(collect($g['nodes'])->where('kind', 'property'))->toHaveCount(1)
        ->and(collect($g['edges'])->where('to', 'bfe:2573669'))->toHaveCount(2);
});

it('falls back to BFE-number label when address is missing and omits missing usage', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => [fdlProperty(['address' => null])], 'usage' => []],
    ]);

    $prop = collect($g['nodes'])->firstWhere('kind', 'property');
    expect($prop['label'])->toBe('BFE 2573669')->and($prop['meta']['usage'])->toBeNull();
});

it('hangs property nodes on the searched company when owner_cvr matches query', function () {
    $query = '38653806';
    $g = buildGraph([
        'query' => $query,
        'properties' => ['list' => [fdlProperty(['owner_cvr' => $query])], 'usage' => ['2573669' => 'Bolig']],
    ]);

    $prop = collect($g['nodes'])->firstWhere('id', 'bfe:2573669');
    expect($prop)->not->toBeNull()
        ->and(collect($g['edges'])->firstWhere('to', 'bfe:2573669')['from'])->toBe('searched');
});

it('preserves existing relations count when adding hidden properties to a node', function () {
    // Grandchildren enlarged to 4 (Task 9: ≤3 hidden descendants now
    // auto-render instead of signalling) so Child still carries a real
    // expand.relations count for this test's properties-roll-up assertion.
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => [
        ['cvr' => '12345678', 'name' => 'Child', 'ownership_share' => 100.0, 'children' => [
            ['cvr' => '87654321', 'name' => 'Grandchild', 'ownership_share' => 100.0, 'children' => []],
            ['cvr' => '87654322', 'name' => 'Grandchild2', 'ownership_share' => 100.0, 'children' => []],
            ['cvr' => '87654323', 'name' => 'Grandchild3', 'ownership_share' => 100.0, 'children' => []],
            ['cvr' => '87654324', 'name' => 'Grandchild4', 'ownership_share' => 100.0, 'children' => []],
        ]],
    ]]];
    // Create 9 properties owned by parent and 9 owned by child
    $parentProps = collect(range(1, 9))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (1000000 + $i)]))->all();
    $childProps = collect(range(1, 9))->map(fn ($i) => fdlProperty(['owner_cvr' => '12345678', 'matrikel_id' => (string) (2000000 + $i)]))->all();
    $allProps = array_merge($parentProps, $childProps);

    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => $allProps, 'usage' => []],
    ]);

    $childNode = collect($g['nodes'])->firstWhere('id', '12345678');
    expect($childNode['expand'])->toHaveKeys(['relations', 'properties'])
        ->and($childNode['expand']['relations'])->toBe(4)
        ->and($childNode['expand']['properties'])->toBe(3);
});

it('enforces the total node cap deterministically: properties are cut before subsidiaries', function () {
    // 100 subsidiaries level 1 + 60 properties → cap 120 must keep ALL company
    // nodes (101 + searched = 101) and cut properties down to fit.
    $subs = collect(range(1, 100))->map(fn ($i) => ['cvr' => (string) (60000000 + $i), 'name' => 'S'.$i, 'ownership_share' => 1.0, 'children' => []])->all();
    $props = collect(range(1, 60))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (2000000 + $i), 'owner_cvr' => (string) (60000000 + ($i % 100) + 1)]))->all();
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => $props, 'usage' => []]]);

    expect(count($g['nodes']))->toBeLessThanOrEqual(120)
        ->and(collect($g['nodes'])->where('kind', 'subsidiary'))->toHaveCount(100);
});

it('truncates deepest subsidiary layer when cap cannot be met by cutting properties alone', function () {
    // 130 subsidiaries level 1, 0 properties → cap 120 must cut the deepest
    // layer (level 1 itself, since there's only one layer). Ancestors untouched.
    $subs = collect(range(1, 130))->map(fn ($i) => ['cvr' => (string) (70000000 + $i), 'name' => 'S'.$i, 'ownership_share' => 1.0, 'children' => []])->all();
    $ancestors = [['person_name' => 'Owner', 'is_company' => false, 'cvr' => null, 'ownership_share' => 100.0, 'parent_of_cvr' => null]];
    $g = buildGraph(['structure' => ['ancestors' => $ancestors, 'subsidiaries' => $subs], 'properties' => ['list' => [], 'usage' => []]]);

    expect(count($g['nodes']))->toBeLessThanOrEqual(120)
        ->and(collect($g['nodes'])->where('kind', 'person'))->toHaveCount(1)
        ->and(collect($g['nodes'])->firstWhere('id', 'searched')['expand']['relations'])->toBeGreaterThan(0);

    // Removed subsidiary nodes must not leave dangling edges.
    $nodeIds = collect($g['nodes'])->pluck('id')->all();
    foreach ($g['edges'] as $edge) {
        expect($nodeIds)->toContain($edge['from'])->toContain($edge['to']);
    }
});

it('flags the parents expand.capped_relations when TOTAL-cap truncation removes a subsidiary node (F3)', function () {
    // 130 FLAT children of 'searched' (depth 1, so no depth-cap ever touches
    // them) forces removeNode() via the TOTAL-node cap alone, folding onto
    // the 'relations' field. expandNode() cannot resolve this hidden count
    // (it only lifts the depth-recursion cap) so the parent's expand array
    // must carry capped_relations: true, telling the Blade to render static
    // text instead of a dead-end expand button.
    $subs = collect(range(1, 130))->map(fn ($i) => ['cvr' => (string) (90000000 + $i), 'name' => 'S'.$i, 'ownership_share' => 1.0, 'children' => []])->all();
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => [], 'usage' => []]]);

    $searched = collect($g['nodes'])->firstWhere('id', 'searched');
    expect($searched['expand']['relations'])->toBeGreaterThan(0)
        ->and($searched['expand']['capped_relations'])->toBeTrue();
});

it('does not set expand.capped_relations on a node whose hidden count comes only from the depth cap', function () {
    // Regression guard: depth-cap signalling (addSubsidiaries, well under the
    // total_nodes cap) must NOT carry capped_relations — those ARE resolvable
    // via expandNode(), so the Blade must still render a real button for them.
    // Hidden level-3 subtree enlarged to 4 nodes (Task 9: ≤3 auto-renders).
    $subs = [[
        'cvr' => '44507781', 'name' => 'A', 'ownership_share' => 50.0,
        'children' => [[
            'cvr' => '44018942', 'name' => 'B', 'ownership_share' => 100.0,
            'children' => [
                ['cvr' => '44027992', 'name' => 'C', 'ownership_share' => 67.0, 'children' => []],
                ['cvr' => '44027993', 'name' => 'D', 'ownership_share' => 1.0, 'children' => []],
                ['cvr' => '44027994', 'name' => 'E', 'ownership_share' => 1.0, 'children' => []],
                ['cvr' => '44027995', 'name' => 'F', 'ownership_share' => 1.0, 'children' => []],
            ],
        ]],
    ]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs]]);

    $trygve1 = collect($g['nodes'])->firstWhere('id', '44018942');
    expect($trygve1['expand']['relations'])->toBe(4);
    expect($trygve1['expand']['capped_relations'] ?? false)->toBeFalse();
});

it('flags only capped_properties, leaving a coexisting legitimate depth-cap relations button clickable (re-review F3 corner)', function () {
    // A node can carry BOTH a legitimate depth-cap relations count (resolvable
    // via expandNode) AND a total-cap-truncated properties count (not
    // resolvable) at once. Tagging the whole expand object with one shared
    // 'capped' flag would incorrectly freeze the still-live relations button
    // too — the flag must be per-field.
    // DepthCappedChild's OWN hidden subtree carries 3 further children of its
    // own, so the total hidden subtree below Root is 4 nodes (> 3) — Task 9's
    // auto-expand-≤3 rule does not kick in here, and Root keeps a real,
    // resolvable expand.relations signal (the point of this test).
    $subs = [[
        'cvr' => '95000000', 'name' => 'Root', 'ownership_share' => 100.0,
        'children' => [[
            // One direct child beyond the depth cap (subsidiary_depth=1) → Root
            // gets a legitimate, resolvable expand.relations = 1 (depth-cap
            // signal, set by addSubsidiaries — never touched by removeNode).
            'cvr' => '95000001', 'name' => 'DepthCappedChild', 'ownership_share' => 100.0, 'children' => [
                ['cvr' => '95000002', 'name' => 'GC1', 'ownership_share' => 1.0, 'children' => []],
                ['cvr' => '95000003', 'name' => 'GC2', 'ownership_share' => 1.0, 'children' => []],
                ['cvr' => '95000004', 'name' => 'GC3', 'ownership_share' => 1.0, 'children' => []],
            ],
        ]],
    ]];
    // 9 properties on Root; a tight total_nodes (2: searched + Root) forces
    // truncateToCap's pass 1 to remove ALL of them via removeNode(), folding
    // the full count onto Root's expand.properties as TOTAL-cap-truncated
    // (not resolvable via expandNode).
    $props = collect(range(1, 9))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (3000000 + $i), 'owner_cvr' => '95000000']))->all();

    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => $props, 'usage' => []],
        'caps' => ['subsidiary_depth' => 1, 'properties_per_company' => 6, 'total_nodes' => 2],
    ]);

    $root = collect($g['nodes'])->firstWhere('id', '95000000');
    // Root's relations count is the legitimate depth-cap signal — still resolvable.
    expect($root['expand']['relations'])->toBe(1);
    expect($root['expand']['capped_relations'] ?? false)->toBeFalse();
    // Root's properties count includes TOTAL-cap-truncated properties — not resolvable.
    expect($root['expand']['properties'])->toBeGreaterThan(0);
    expect($root['expand']['capped_properties'])->toBeTrue();
});

it('rolls up a removed subsidiary\'s own hidden children onto the parent, not just +1', function () {
    // One depth-1 root with one depth-2 child that itself has 5 depth-3
    // children hidden behind the depth cap (subsidiary_depth = 2, so the
    // depth-2 node carries expand.relations = 5 for them). A tight total_nodes
    // cap forces the deepest RENDERED layer (the depth-2 node) to be cut.
    // The root must then absorb 1 (the depth-2 node itself) + 5 (its
    // already-hidden grandchildren) = 6, not just 1.
    $grandchildren = collect(range(1, 5))->map(fn ($i) => ['cvr' => (string) (80001000 + $i), 'name' => 'GC'.$i, 'ownership_share' => 1.0, 'children' => []])->all();
    $subs = [[
        'cvr' => '80000001', 'name' => 'Root', 'ownership_share' => 100.0,
        'children' => [[
            'cvr' => '80000002', 'name' => 'Mid', 'ownership_share' => 100.0,
            'children' => $grandchildren,
        ]],
    ]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'caps' => ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 2],
    ]);

    // Only the searched node + the depth-1 root should remain; the depth-2
    // node was the deepest rendered layer and got cut.
    expect(collect($g['nodes'])->pluck('id'))->not->toContain('80000002')
        ->and(collect($g['nodes'])->firstWhere('id', '80000001')['expand']['relations'])->toBe(6);
});

it('removes edges where the truncated node is the FROM endpoint, not only the TO endpoint', function () {
    // A depth-2 node has an outbound edge to a depth-3 child that survived
    // because it was expanded (props:/sub:-style), so when the depth-2 node
    // itself gets cut by the cap, its outbound edge (from = removed node)
    // must also disappear — not just its inbound edge (to = removed node).
    $subs = [[
        'cvr' => '81000001', 'name' => 'Root', 'ownership_share' => 100.0,
        'children' => [[
            'cvr' => '81000002', 'name' => 'Mid', 'ownership_share' => 100.0,
            'children' => [[
                'cvr' => '81000003', 'name' => 'Leaf', 'ownership_share' => 100.0, 'children' => [],
            ]],
        ]],
    ]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'expandedNodeIds' => ['sub:81000002'], // renders the depth-3 leaf too
        'caps' => ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 2],
    ]);

    $nodeIds = collect($g['nodes'])->pluck('id')->all();
    expect($nodeIds)->not->toContain('81000002')
        ->and($nodeIds)->not->toContain('81000003') // deepest layer cut too, or orphaned otherwise
        ->and(collect($g['edges'])->where('from', '81000002'))->toBeEmpty()
        ->and(collect($g['edges'])->where('to', '81000002'))->toBeEmpty();

    foreach ($g['edges'] as $edge) {
        expect($nodeIds)->toContain($edge['from'])->toContain($edge['to']);
    }
});

it('increments expand.properties for BOTH co-owners when a shared property is truncated by the total cap (F1)', function () {
    // Two companies co-own the same property (dedup shape explicitly tested
    // elsewhere: one bfe: node, two owner edges). A tight total_nodes cap
    // forces pass 1 to remove that single property node — since it has TWO
    // inbound edges, BOTH owners must have their expand.properties
    // incremented and capped_properties flagged, not just the last one a
    // naive single-$parentId variable would have overwritten to.
    $subs = [
        ['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []],
        ['cvr' => '45170209', 'name' => 'I', 'ownership_share' => 100.0, 'children' => []],
    ];
    $props = [fdlProperty(), fdlProperty(['owner_cvr' => '45170209'])];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => $props, 'usage' => []],
        // searched + 2 subsidiaries = 3 nodes; cap 3 forces the shared
        // property (the 4th node) to be cut in pass 1.
        'caps' => ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 3],
    ]);

    expect(collect($g['nodes'])->pluck('id'))->not->toContain('bfe:2573669');

    $k = collect($g['nodes'])->firstWhere('id', '44507781');
    $i = collect($g['nodes'])->firstWhere('id', '45170209');

    expect($k['expand']['properties'])->toBe(1)
        ->and($k['expand']['capped_properties'])->toBeTrue()
        ->and($i['expand']['properties'])->toBe(1)
        ->and($i['expand']['capped_properties'])->toBeTrue();
});

it('does not crash when a removed subsidiary with its own expand.properties has a co-owned property, thanks to pass ordering (F1 corner)', function () {
    // Architectural note (see removeNode docblock): the properties pass
    // ALWAYS runs before the subsidiary pass in truncateToCap, so a
    // multi-parent (co-owned) removal is never combined with a non-zero
    // expand.relations roll-up in the same removeNode() call — the two
    // "hard" cases (multi-parent, and non-zero roll-up) never overlap in
    // practice. This test documents and asserts that invariant rather than
    // fabricating an impossible combined case: it removes a subsidiary that
    // itself already carries expand.properties (from pass 1 truncation) and
    // confirms pass 2 folds only the relations roll-up, leaving the
    // pre-existing properties count untouched.
    $subs = [[
        'cvr' => '82000001', 'name' => 'Root', 'ownership_share' => 100.0,
        'children' => [[
            'cvr' => '82000002', 'name' => 'Mid', 'ownership_share' => 100.0,
            'children' => [],
        ]],
    ]];
    // 9 properties owned by Mid: pass 1 (tight cap) truncates them down,
    // leaving Mid with expand.properties > 0 BEFORE pass 2 ever runs.
    $props = collect(range(1, 9))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (4000000 + $i), 'owner_cvr' => '82000002']))->all();

    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => $props, 'usage' => []],
        // searched + Root + Mid = 3 nodes; cap 2 forces pass 1 to strip all
        // properties first, then pass 2 to cut the deepest subsidiary (Mid).
        'caps' => ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 2],
    ]);

    $nodeIds = collect($g['nodes'])->pluck('id');
    expect($nodeIds)->not->toContain('82000002');

    $root = collect($g['nodes'])->firstWhere('id', '82000001');
    // Mid had no expand.relations of its own (no depth-capped children), so
    // the roll-up onto Root is just +1 for Mid itself — no crash, no
    // cross-contamination between the 'properties' work pass 1 already did
    // and the 'relations' field pass 2 folds now.
    expect($root['expand']['relations'])->toBe(1)
        ->and($root['expand']['capped_relations'])->toBeTrue();

    foreach ($g['edges'] as $edge) {
        expect($nodeIds)->toContain($edge['from'])->toContain($edge['to']);
    }
});

it('derives value aggregate per owner from the property list with coverage', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $props = [
        fdlProperty(['matrikel_id' => '1000001', 'valuation' => 500000]),
        fdlProperty(['matrikel_id' => '1000002', 'valuation' => null]),
    ];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => $props, 'usage' => []]]);

    expect(collect($g['nodes'])->firstWhere('id', '44507781')['agg'])
        ->toMatchArray(['count' => 2, 'value' => 500000, 'valued' => 1]);
});

it('computes signals in the builder with a deterministic now', function () {
    $enrichment = ['companies' => ['44507781' => ['equity' => -12000.0, 'fiscal_year' => '2025', 'founded_date' => '2026-03-01']], 'properties' => []];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]]],
        'enrichment' => $enrichment,
        'now' => \Carbon\CarbonImmutable::parse('2026-07-26'),
    ]);

    expect(collect($g['nodes'])->firstWhere('id', '44507781')['signals'])
        ->toContain('negative_equity')->toContain('newly_founded');
});

it('marks enriched companies without financials explicitly and leaves unenriched nodes without signals key', function () {
    $enrichment = ['companies' => ['44507781' => ['founded_date' => '2010-01-01']], 'properties' => []];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => [
            ['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []],
            ['cvr' => '45170209', 'name' => 'I', 'ownership_share' => 100.0, 'children' => []],
        ]],
        'enrichment' => $enrichment,
        'now' => \Carbon\CarbonImmutable::parse('2026-07-26'),
    ]);

    expect(collect($g['nodes'])->firstWhere('id', '44507781')['signals'])->toContain('no_financials')
        ->and(collect($g['nodes'])->firstWhere('id', '45170209'))->not->toHaveKey('signals');
});

it('attaches property card data from the enrichment property map', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => [fdlProperty(['latitude' => 55.25, 'longitude' => 12.17])], 'usage' => []],
        'enrichment' => ['companies' => [], 'properties' => ['2573669' => ['usage' => 'Fritliggende enfamiliehus', 'latest_sale_price' => 1200000, 'streetview_url' => 'https://example/sv']]],
    ]);

    $card = collect($g['nodes'])->firstWhere('id', 'bfe:2573669')['card'];
    expect($card)->toMatchArray(['usage' => 'Fritliggende enfamiliehus', 'latest_sale_price' => 1200000, 'streetview_url' => 'https://example/sv', 'lat' => 55.25, 'lng' => 12.17])
        ->and($card)->not->toHaveKey('valuation');   // null-felter udeladt
});

it('remains deterministic and idempotent with enrichment input', function () {
    $args = ['structure' => ['ancestors' => [], 'subsidiaries' => [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]]],
        'enrichment' => ['companies' => ['44507781' => ['equity' => 5.0]], 'properties' => []],
        'now' => \Carbon\CarbonImmutable::parse('2026-07-26')];
    expect(buildGraph($args))->toEqual(buildGraph($args));
});

/**
 * Spec (fase 2a.2): person/foreign nodes get NO enrichment before fase 2b —
 * even when a person row happens to carry a cvr (is_company=false ancestor
 * rows sometimes still have a stray cvr in the raw registry payload). Before
 * this fix, applyEnrichment() only gated on cvr !== null, so a person node
 * with a cvr would get a company card/signals meant for an actual company.
 */
it('does not attach company card/signals to a person node even when it carries a cvr (F3)', function () {
    $g = buildGraph([
        'structure' => ['ancestors' => [
            ['person_name' => 'Frederik Larnæs', 'is_company' => false, 'cvr' => '11111111', 'ownership_share' => 100.0, 'parent_of_cvr' => null],
        ], 'subsidiaries' => []],
        'enrichment' => ['companies' => ['11111111' => ['equity' => 5.0, 'website' => 'example.dk']], 'properties' => []],
    ]);

    $person = collect($g['nodes'])->firstWhere('id', '11111111');
    expect($person['kind'])->toBe('person')
        ->and($person)->not->toHaveKey('card')
        ->and($person)->not->toHaveKey('signals');
});

/**
 * 'other' orphan-parent stub nodes (synthesised in addAncestors() when a
 * parent_of_cvr was pruned upstream) are placeholders, not companies a user
 * asked to see enriched — even if enrichment data happens to be present for
 * that cvr (e.g. left over from a previous graph that had the real company
 * loaded), the stub must not render a card.
 */
it('does not attach company card/signals to an "other" orphan-parent stub node (F3)', function () {
    $g = buildGraph([
        'structure' => ['ancestors' => [
            ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '22222222', 'ownership_share' => 100.0, 'parent_of_cvr' => '99999999'],
        ], 'subsidiaries' => []],
        'enrichment' => ['companies' => ['99999999' => ['equity' => 5.0]], 'properties' => []],
    ]);

    $stub = collect($g['nodes'])->firstWhere('id', '99999999');
    expect($stub['kind'])->toBe('other')
        ->and($stub)->not->toHaveKey('card')
        ->and($stub)->not->toHaveKey('signals');
});

/*
|--------------------------------------------------------------------------
| buildForPerson() — fase 2b, skelet-laget (Task 4)
|--------------------------------------------------------------------------
|
| The person entry point: the person is the root (id 'person:root', never a
| CPR), ownership companies hang below with solid %-edges, role companies
| with dashed role-labelled edges, and cross-ownership demotes an
| ownership-set company that is owned by ANOTHER company in the set from
| root to child. $structures/$properties are always empty maps at this
| stage — progressive loading lands in Task 5.
*/

/** 2a caps + the fase 2b first-level caps. */
function personCaps(array $overrides = []): array
{
    return array_merge([
        'subsidiary_depth' => 2,
        'properties_per_company' => 6,
        'total_nodes' => 120,
        'person_roots' => 20,
        'person_roles' => 15,
    ], $overrides);
}

function buildPersonGraph(array $overrides = []): array
{
    $builder = new OwnershipGraphBuilder;

    return $builder->buildForPerson(
        personName: $overrides['personName'] ?? 'Lars Sørensen',
        ownershipCompanies: $overrides['ownershipCompanies'] ?? [],
        roleCompanies: $overrides['roleCompanies'] ?? [],
        crossOwnership: $overrides['crossOwnership'] ?? [],
        structures: $overrides['structures'] ?? [],
        properties: $overrides['properties'] ?? [],
        enrichment: $overrides['enrichment'] ?? [],
        expandedNodeIds: $overrides['expandedNodeIds'] ?? [],
        layers: $overrides['layers'] ?? ['ownership', 'roles'],
        caps: $overrides['caps'] ?? personCaps(),
        now: $overrides['now'] ?? null,
    );
}

function ownershipCompany(string $cvr, ?float $share = 100.0, string $name = 'Holding ApS'): array
{
    return ['cvr' => $cvr, 'name' => $name, 'company_type' => 'ApS', 'ownership_share' => $share];
}

function roleCompany(string $cvr, ?string $label = 'bestyrelse', string $name = 'Resights ApS'): array
{
    return ['cvr' => $cvr, 'name' => $name, 'company_type' => 'ApS', 'role_label' => $label];
}

// --- Regel 1: person-roden -------------------------------------------------

it('buildForPerson: emits a person:root node with the exact 2b root shape (regel 1)', function () {
    $g = buildPersonGraph(['personName' => 'Lars Sørensen']);

    expect($g['nodes'])->toHaveCount(1)
        ->and($g['nodes'][0])->toBe([
            'id' => 'person:root',
            'label' => 'Lars Sørensen',
            'cvr' => null,
            'kind' => 'person',
            'share' => null,
            'expand' => null,
        ])
        ->and($g['edges'])->toBeEmpty();
});

// --- Regel 2: cross-ownership-dedup ---------------------------------------

it('buildForPerson: demotes a cross-owned company from root to child with a parent edge in the skeleton (regel 2)', function () {
    // Person owns Holding directly; Holding owns Drift. Drift is NOT in the
    // person's ownership list, so it only enters via the relationship — as a
    // depth-2 child hanging on Holding, never on the person.
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('40072772', 100.0, 'Holding')],
        'crossOwnership' => [['parent_cvr' => '40072772', 'child_cvr' => '44018942', 'ownership_share' => 60.0]],
    ]);

    $child = collect($g['nodes'])->firstWhere('id', '44018942');
    expect($child)->not->toBeNull()
        ->and($child['kind'])->toBe('subsidiary')
        ->and($child['depth'])->toBe(2)
        ->and(collect($g['edges'])->where('to', '44018942'))->toHaveCount(1)
        ->and(collect($g['edges'])->firstWhere('to', '44018942'))
        ->toMatchArray(['from' => '40072772', 'label' => '60 %'])
        ->and(collect($g['edges'])->firstWhere('to', '44018942'))->not->toHaveKey('style');
});

it('buildForPerson: a cross-owned child that the person ALSO owns directly keeps BOTH edges and is not a root (regel 2)', function () {
    $g = buildPersonGraph([
        'ownershipCompanies' => [
            ownershipCompany('40072772', 100.0, 'Holding'),
            ownershipCompany('44018942', 40.0, 'Drift'),
        ],
        'crossOwnership' => [['parent_cvr' => '40072772', 'child_cvr' => '44018942', 'ownership_share' => 60.0]],
    ]);

    $drift = collect($g['nodes'])->firstWhere('id', '44018942');
    expect(collect($g['nodes'])->where('id', '44018942'))->toHaveCount(1)
        ->and($drift['depth'])->toBe(2)                       // child, not a first-level root
        ->and(collect($g['edges'])->where('to', '44018942'))->toHaveCount(2)
        ->and(collect($g['edges'])->firstWhere('from', 'person:root')['to'])->toBe('40072772');

    $direct = collect($g['edges'])->where('to', '44018942')->firstWhere('from', 'person:root');
    $viaParent = collect($g['edges'])->where('to', '44018942')->firstWhere('from', '40072772');
    expect($direct['label'])->toBe('40 %')->and($viaParent['label'])->toBe('60 %');
});

it('buildForPerson: a relationship whose parent is outside the ownership set does not demote the child (regel 2)', function () {
    // Only cvr'er whose PARENT is also in the ownership set are children.
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('44018942', 40.0, 'Drift')],
        'crossOwnership' => [['parent_cvr' => '99999999', 'child_cvr' => '44018942', 'ownership_share' => 60.0]],
    ]);

    $drift = collect($g['nodes'])->firstWhere('id', '44018942');
    expect($drift['depth'])->toBe(1)
        ->and(collect($g['edges'])->firstWhere('to', '44018942')['from'])->toBe('person:root')
        ->and(collect($g['nodes'])->pluck('id'))->not->toContain('99999999');
});

// --- Regel 3: ejerskabs-rødder --------------------------------------------

it('buildForPerson: ownership roots hang on the person with a %-label and no style key (regel 3)', function () {
    $g = buildPersonGraph(['ownershipCompanies' => [ownershipCompany('40072772', 100.0)]]);

    $edge = collect($g['edges'])->firstWhere('to', '40072772');
    expect($edge['from'])->toBe('person:root')
        ->and($edge['label'])->toBe('100 %')
        ->and($edge)->not->toHaveKey('style');
});

it('buildForPerson: an ownership root with a null share gets an empty edge label (regel 3)', function () {
    $g = buildPersonGraph(['ownershipCompanies' => [ownershipCompany('40072772', null)]]);

    expect(collect($g['edges'])->firstWhere('to', '40072772')['label'])->toBe('');
});

it('buildForPerson: the ownership layer is omitted entirely when not in $layers (regel 3)', function () {
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('40072772')],
        'layers' => ['roles'],
    ]);

    expect(collect($g['nodes'])->pluck('id'))->not->toContain('40072772')
        ->and($g['edges'])->toBeEmpty();
});

// --- Regel 4: rolle-selskaber ---------------------------------------------

it('buildForPerson: role edges are dashed with role label, ownership edges solid with % (regel 4)', function () {
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('40072772', 100.0, 'Holding')],
        'roleCompanies' => [roleCompany('41527080', 'bestyrelse', 'Resights')],
    ]);

    $own = collect($g['edges'])->firstWhere('to', '40072772');
    $role = collect($g['edges'])->firstWhere('to', '41527080');
    expect($own['label'])->toBe('100 %')->and($own)->not->toHaveKey('style')
        ->and($role['style'])->toBe('dashed')->and($role['label'])->toBe('bestyrelse')
        ->and($role['from'])->toBe('person:root');
});

it('buildForPerson: a role company without a label falls back to "rolle" and is marked role_layer (regel 4)', function () {
    $g = buildPersonGraph(['roleCompanies' => [roleCompany('41527080', null)]]);

    expect(collect($g['edges'])->firstWhere('to', '41527080')['label'])->toBe('rolle')
        ->and(collect($g['nodes'])->firstWhere('id', '41527080'))
        ->toMatchArray(['kind' => 'subsidiary', 'depth' => 1, 'role_layer' => true]);
});

it('buildForPerson: the role layer is omitted entirely when not in $layers (regel 4)', function () {
    $g = buildPersonGraph([
        'roleCompanies' => [roleCompany('41527080')],
        'layers' => ['ownership'],
    ]);

    expect($g['nodes'])->toHaveCount(1)->and($g['edges'])->toBeEmpty();
});

// --- Regel 5: lag-bevidst dobbelt-relation-dedup --------------------------

it('buildForPerson: a company in BOTH lists renders only in the ownership layer when both layers are active (regel 5)', function () {
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('40072772', 100.0, 'Dobbelt')],
        'roleCompanies' => [roleCompany('40072772', 'direktør', 'Dobbelt')],
    ]);

    $edges = collect($g['edges'])->where('to', '40072772');
    expect(collect($g['nodes'])->where('id', '40072772'))->toHaveCount(1)
        ->and($edges)->toHaveCount(1)
        ->and($edges->first()['label'])->toBe('100 %')
        ->and($edges->first())->not->toHaveKey('style')
        ->and(collect($g['nodes'])->firstWhere('id', '40072772'))->not->toHaveKey('role_layer');
});

it('buildForPerson: a double-relation company does not consume a role-cap slot when the ownership layer is active (regel 5)', function () {
    // The company is dropped from the ROLE list (not merely from the role
    // edge) when ownership wins the dedup — otherwise it would silently eat
    // one of the 15 role slots and push a genuine role-only company out of
    // the skeleton, and count towards the person root's hidden tally.
    // 16 role rows, the FIRST of which is the double-relation company, against
    // a 15-role cap. Dropped from the list, the remaining 15 role-only
    // companies all fit and nothing is hidden. Merely suppressing its EDGE
    // (leaving it in the list) would let it occupy slot 1, push R16 out and
    // leave the person root with a hidden count of 1.
    $roles = collect(range(1, 16))->map(fn ($i) => roleCompany((string) (60000000 + $i), 'bestyrelse', 'R'.$i))->all();
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('60000001', 100.0, 'Dobbelt')],
        'roleCompanies' => $roles,
        'caps' => personCaps(['person_roles' => 15]),
    ]);

    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('60000016')
        ->and(collect($g['nodes'])->firstWhere('id', 'person:root')['expand'])->toBeNull()
        ->and(collect($g['edges'])->where('to', '60000001'))->toHaveCount(1)
        ->and(collect($g['edges'])->where('style', 'dashed'))->toHaveCount(15);
});

it('buildForPerson: the same company renders with its dashed role edge when ONLY the roles layer is active (regel 5)', function () {
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('40072772', 100.0, 'Dobbelt')],
        'roleCompanies' => [roleCompany('40072772', 'direktør', 'Dobbelt')],
        'layers' => ['roles'],
    ]);

    $edges = collect($g['edges'])->where('to', '40072772');
    expect(collect($g['nodes'])->where('id', '40072772'))->toHaveCount(1)
        ->and($edges)->toHaveCount(1)
        ->and($edges->first()['label'])->toBe('direktør')
        ->and($edges->first()['style'])->toBe('dashed');
});

// --- Regel 6: rolle-selskab der også er subsidiary ------------------------

it('buildForPerson: a role company that is also a cross-owned subsidiary is ONE node with BOTH edges (regel 6)', function () {
    // Person owns Holding, Holding owns Drift (cross-ownership), and the
    // person sits on Drift's board. Drift must be a single node carrying a
    // dashed person edge AND a solid parent edge.
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('40072772', 100.0, 'Holding')],
        'roleCompanies' => [roleCompany('44018942', 'direktør', 'Drift')],
        'crossOwnership' => [['parent_cvr' => '40072772', 'child_cvr' => '44018942', 'ownership_share' => 60.0]],
    ]);

    $edges = collect($g['edges'])->where('to', '44018942');
    $nodeMatches = collect($g['nodes'])->where('id', '44018942');
    expect($nodeMatches)->toHaveCount(1)
        ->and($edges)->toHaveCount(2);

    $node = $nodeMatches->first();
    // The demotion loop writes this node first (it runs before the role
    // loop), so the $seen-dedup race must not leave it with the generic
    // 'CVR <id>' fallback label or role_layer unset — it IS the role
    // company, and must carry the role company's real name + role_layer,
    // regardless of which loop happens to write it first.
    expect($node['label'])->toBe('Drift')
        ->and($node['role_layer'] ?? null)->toBeTrue();

    $roleEdge = $edges->firstWhere('from', 'person:root');
    $parentEdge = $edges->firstWhere('from', '40072772');
    expect($roleEdge['style'])->toBe('dashed')->and($roleEdge['label'])->toBe('direktør')
        ->and($parentEdge['label'])->toBe('60 %')->and($parentEdge)->not->toHaveKey('style');
});

// --- Regel 7: first-level caps --------------------------------------------

it('buildForPerson: caps ownership roots and role companies and folds the rest onto the person root expand (regel 7)', function () {
    $owners = collect(range(1, 25))->map(fn ($i) => ownershipCompany((string) (50000000 + $i), 1.0, 'O'.$i))->all();
    $roles = collect(range(1, 20))->map(fn ($i) => roleCompany((string) (60000000 + $i), 'bestyrelse', 'R'.$i))->all();

    $g = buildPersonGraph(['ownershipCompanies' => $owners, 'roleCompanies' => $roles]);

    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('50000020')->not->toContain('50000021')   // 20 roots, input order
        ->and($ids)->toContain('60000015')->not->toContain('60000016') // 15 roles, input order
        ->and(collect($g['nodes'])->firstWhere('id', 'person:root')['expand'])
        ->toBe(['relations' => 10, 'properties' => 0]);               // 5 hidden roots + 5 hidden roles
});

it('buildForPerson: sub:person:root lifts the roots cap only, roles:person:root the roles cap only (regel 7)', function () {
    $owners = collect(range(1, 25))->map(fn ($i) => ownershipCompany((string) (50000000 + $i), 1.0, 'O'.$i))->all();
    $roles = collect(range(1, 20))->map(fn ($i) => roleCompany((string) (60000000 + $i), 'bestyrelse', 'R'.$i))->all();

    $rootsLifted = buildPersonGraph([
        'ownershipCompanies' => $owners, 'roleCompanies' => $roles,
        'expandedNodeIds' => ['sub:person:root'],
    ]);
    expect(collect($rootsLifted['nodes'])->pluck('id'))->toContain('50000025')->not->toContain('60000016')
        ->and(collect($rootsLifted['nodes'])->firstWhere('id', 'person:root')['expand'])
        ->toBe(['relations' => 5, 'properties' => 0]);

    $rolesLifted = buildPersonGraph([
        'ownershipCompanies' => $owners, 'roleCompanies' => $roles,
        'expandedNodeIds' => ['roles:person:root'],
    ]);
    expect(collect($rolesLifted['nodes'])->pluck('id'))->toContain('60000020')->not->toContain('50000021')
        ->and(collect($rolesLifted['nodes'])->firstWhere('id', 'person:root')['expand'])
        ->toBe(['relations' => 5, 'properties' => 0]);

    $both = buildPersonGraph([
        'ownershipCompanies' => $owners, 'roleCompanies' => $roles,
        'expandedNodeIds' => ['sub:person:root', 'roles:person:root'],
    ]);
    expect(collect($both['nodes'])->firstWhere('id', 'person:root')['expand'])->toBeNull();
});

// --- Regel 8: node-shape --------------------------------------------------

it('buildForPerson: all company nodes are kind subsidiary, roots/role companies at depth 1 (regel 8)', function () {
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('40072772', 100.0, 'Holding')],
        'roleCompanies' => [roleCompany('41527080', 'bestyrelse', 'Resights')],
    ]);

    $root = collect($g['nodes'])->firstWhere('id', '40072772');
    $role = collect($g['nodes'])->firstWhere('id', '41527080');
    expect($root)->toMatchArray(['cvr' => '40072772', 'label' => 'Holding', 'kind' => 'subsidiary', 'share' => 100.0, 'depth' => 1, 'expand' => null])
        ->and($role)->toMatchArray(['cvr' => '41527080', 'label' => 'Resights', 'kind' => 'subsidiary', 'share' => null, 'depth' => 1, 'expand' => null]);
});

// --- CPR-lækage + determinisme + finalize-integration ---------------------

it('buildForPerson: node ids never contain a cpr-like string', function () {
    $g = buildPersonGraph(['personName' => 'X']);

    expect(json_encode($g))->not->toMatch('/\d{10}/');
});

it('buildForPerson: is deterministic across identical builds', function () {
    $args = [
        'ownershipCompanies' => [ownershipCompany('40072772', 100.0, 'Holding')],
        'roleCompanies' => [roleCompany('41527080', 'bestyrelse', 'Resights')],
        'crossOwnership' => [['parent_cvr' => '40072772', 'child_cvr' => '44018942', 'ownership_share' => 60.0]],
    ];

    expect(buildPersonGraph($args))->toEqual(buildPersonGraph($args));
});

it('buildForPerson: enriches role and ownership companies through the shared finalize() tail', function () {
    $g = buildPersonGraph([
        'ownershipCompanies' => [ownershipCompany('40072772', 100.0, 'Holding')],
        'roleCompanies' => [roleCompany('41527080', 'bestyrelse', 'Resights')],
        'enrichment' => ['companies' => [
            '40072772' => ['equity' => -5.0],
            '41527080' => ['equity' => 12.0, 'website' => 'resights.dk'],
        ], 'properties' => []],
        'now' => \Carbon\CarbonImmutable::parse('2026-07-27'),
    ]);

    expect(collect($g['nodes'])->firstWhere('id', '40072772')['signals'])->toContain('negative_equity')
        ->and(collect($g['nodes'])->firstWhere('id', '41527080')['card'])->toMatchArray(['website' => 'resights.dk'])
        ->and(collect($g['nodes'])->firstWhere('id', 'person:root'))->not->toHaveKey('card');
});

it('buildForPerson: the total node cap still applies via finalize()', function () {
    $owners = collect(range(1, 20))->map(fn ($i) => ownershipCompany((string) (50000000 + $i), 1.0, 'O'.$i))->all();
    $g = buildPersonGraph([
        'ownershipCompanies' => $owners,
        'caps' => personCaps(['total_nodes' => 10]),
    ]);

    expect(count($g['nodes']))->toBeLessThanOrEqual(10)
        ->and(collect($g['nodes'])->firstWhere('id', 'person:root'))->not->toBeNull();

    $nodeIds = collect($g['nodes'])->pluck('id')->all();
    foreach ($g['edges'] as $edge) {
        expect($nodeIds)->toContain($edge['from'])->toContain($edge['to']);
    }
});
