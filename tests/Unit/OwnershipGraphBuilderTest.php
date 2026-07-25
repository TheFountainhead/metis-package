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
