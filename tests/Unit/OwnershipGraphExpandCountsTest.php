<?php

use TheFountainhead\Metis\Services\OwnershipGraphBuilder;

/**
 * Expand-tællere på noder der HAR uudfoldede relationer.
 *
 * 🔑 Målt 1/8 mod Resights: deres graf viser ALTID hvor mange relationer en
 * node skjuler (`↓ 4 relationer`), så udvidelse er et bevidst valg frem for en
 * overraskelse. Vi havde allerede knappen og `expandNode()` — men tælleren blev
 * kun sat når noder var skåret væk af caps. En graf uden beskæring viste
 * `expand=null` på hver eneste node.
 *
 * Forskellen er en hentestrategi, ikke en manglende funktion: vi henter alt på
 * forhånd og beskærer, de henter progressivt. Denne ændring lukker gabet uden
 * at røre strategien — tælleren sættes nu også når en node har relationer, vi
 * kender men ikke har tegnet.
 *
 * Progressiv hentning (opgave B) er noteret som selvstændig opfølgning.
 */
function expandGraph(array $overrides = []): array
{
    return (new OwnershipGraphBuilder)->build(
        query: $overrides['query'] ?? '29798486',
        companyName: $overrides['companyName'] ?? 'AKACIETORVET ApS',
        structure: $overrides['structure'] ?? ['ancestors' => [], 'subsidiaries' => []],
        properties: $overrides['properties'] ?? ['list' => [], 'usage' => []],
        enrichment: $overrides['enrichment'] ?? [],
        expandedNodeIds: $overrides['expandedNodeIds'] ?? [],
        caps: $overrides['caps'] ?? ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 120],
        now: $overrides['now'] ?? null,
        layers: $overrides['layers'] ?? ['owners', 'subsidiaries', 'properties'],
    );
}

/** Et selskab med N datterselskaber, hver uden egne børn. */
function subsidiaries(int $count): array
{
    return collect(range(1, $count))
        ->map(fn ($i) => [
            'cvr' => str_pad((string) (11111110 + $i), 8, '0', STR_PAD_LEFT),
            'name' => 'Datter '.$i,
            'ownership_share' => 100.0,
            'children' => [],
        ])
        ->all();
}

it('sætter tælleren på en node hvis børn er skåret væk af cappen', function () {
    // Den oprindelige adfærd — skal fortsat virke.
    $g = expandGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => subsidiaries(10)],
        'caps' => ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 5],
    ]);

    $root = collect($g['nodes'])->firstWhere('kind', 'searched');

    expect($root['expand']['relations'] ?? 0)->toBeGreaterThan(0)
        ->and($root['expand']['capped_relations'] ?? false)->toBeTrue();
});

it('sætter IKKE tælleren når alt allerede er tegnet', function () {
    // 🚨 Invarianten: en node uden skjulte relationer må ikke få en knap der
    // lover noget, der ikke findes. Et klik ville udvide til ingenting.
    $g = expandGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => subsidiaries(2)],
    ]);

    foreach ($g['nodes'] as $node) {
        expect($node['expand']['relations'] ?? 0)->toBe(0);
    }
});

it('små skjulte undertræer tegnes frem for at kræve et klik', function () {
    // 🔑 Bevidst beslutning fra tidligere arbejde: er hele det skjulte undertræ
    // ≤3 noder, tegnes det fuldt ud i stedet for at gemmes bag en knap. En
    // lineær kæde af skjulte efterkommere ville koste ét klik pr. niveau uden
    // at spare plads.
    //
    // Denne test pinner beslutningen, så en fremtidig ændring skal tage
    // stilling til den frem for at bryde den ubemærket.
    $g = expandGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => [
            ['cvr' => '11111111', 'name' => 'Datter 1', 'ownership_share' => 100.0, 'children' => [
                ['cvr' => '22222222', 'name' => 'Barnebarn A', 'ownership_share' => 100.0, 'children' => []],
                ['cvr' => '33333333', 'name' => 'Barnebarn B', 'ownership_share' => 100.0, 'children' => []],
            ]],
        ]],
        'caps' => ['subsidiary_depth' => 1, 'properties_per_company' => 6, 'total_nodes' => 120],
    ]);

    // Tegnet, ikke gemt — og derfor ingen knap.
    expect(collect($g['nodes'])->firstWhere('cvr', '22222222'))->not->toBeNull()
        ->and(collect($g['nodes'])->firstWhere('cvr', '11111111')['expand']['relations'] ?? 0)->toBe(0);
});

it('tæller skjulte børn når undertræet er for stort til at tegne', function () {
    // Over ≤3-grænsen gælder knappen: fire børn skjules, og noden fortæller
    // hvor mange. Det er den affordance Resights viser på HVER node.
    $children = collect(range(1, 4))->map(fn ($i) => [
        'cvr' => str_pad((string) (22222220 + $i), 8, '0', STR_PAD_LEFT),
        'name' => 'Barnebarn '.$i, 'ownership_share' => 100.0, 'children' => [],
    ])->all();

    $g = expandGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => [
            ['cvr' => '11111111', 'name' => 'Datter 1', 'ownership_share' => 100.0, 'children' => $children],
        ]],
        'caps' => ['subsidiary_depth' => 1, 'properties_per_company' => 6, 'total_nodes' => 120],
    ]);

    $datter = collect($g['nodes'])->firstWhere('cvr', '11111111');

    expect($datter['expand']['relations'] ?? 0)->toBe(4)
        ->and(collect($g['nodes'])->firstWhere('cvr', '22222221'))->toBeNull();
});

it('nulstiller tælleren når noden er udfoldet', function () {
    // Efter et klik er børnene tegnet, og knappen skal forsvinde — ellers
    // inviterer den til et klik der ikke gør noget.
    $g = expandGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => [
            ['cvr' => '11111111', 'name' => 'Datter 1', 'ownership_share' => 100.0, 'children' => [
                ['cvr' => '22222222', 'name' => 'Barnebarn A', 'ownership_share' => 100.0, 'children' => []],
            ]],
        ]],
        'caps' => ['subsidiary_depth' => 1, 'properties_per_company' => 6, 'total_nodes' => 120],
        'expandedNodeIds' => ['sub:11111111'],
    ]);

    $datter = collect($g['nodes'])->firstWhere('cvr', '11111111');

    expect($datter['expand']['relations'] ?? 0)->toBe(0)
        ->and(collect($g['nodes'])->firstWhere('cvr', '22222222'))->not->toBeNull();
});
