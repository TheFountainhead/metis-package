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
        enrichment: [],
        expandedNodeIds: $overrides['expandedNodeIds'] ?? [],
        caps: $overrides['caps'] ?? ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 120],
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

it('adds subsidiaries two levels deep with edges parent→child', function () {
    $subs = [[
        'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0,
        'children' => [[
            'cvr' => '44018942', 'name' => 'Trygve 1 ApS', 'ownership_share' => 100.0,
            'children' => [[
                'cvr' => '44027992', 'name' => 'Schneidereit Trygve 1 A/S', 'ownership_share' => 67.0, 'children' => [],
            ]],
        ]],
    ]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs]]);

    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('44507781')->toContain('44018942')
        ->and($ids)->not->toContain('44027992')                       // level 3 truncated (depth cap 2)
        ->and(collect($g['edges'])->firstWhere('to', '44507781')['from'])->toBe('searched')
        ->and(collect($g['edges'])->firstWhere('to', '44018942')['from'])->toBe('44507781');

    $trygve1 = collect($g['nodes'])->firstWhere('id', '44018942');
    expect($trygve1['expand']['relations'])->toBe(1);                 // 1 hidden child signalled
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
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => [
        ['cvr' => '12345678', 'name' => 'Child', 'ownership_share' => 100.0, 'children' => [
            ['cvr' => '87654321', 'name' => 'Grandchild', 'ownership_share' => 100.0, 'children' => []],
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
        ->and($childNode['expand']['relations'])->toBe(1)
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
