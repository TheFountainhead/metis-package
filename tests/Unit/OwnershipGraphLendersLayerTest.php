<?php

use TheFountainhead\Metis\Services\OwnershipGraphBuilder;

/**
 * Långiver-laget: grafens fjerde lag.
 *
 * 🎯 Det er her vi går forbi konkurrenterne. Resights' koncerngraf stopper ved
 * ejendommene — analyseret fra skærmoptagelse 1/8: person → holding → selskab →
 * ejendom, og så ikke længere. Hvem der har pant i ejendommene fremgår ikke.
 *
 * Med dette lag kan grafen vise hele kæden fra reel ejer til finansierende bank:
 *
 *   Jakob Brandtberg Knudsen
 *     └─ JAKOB KNUDSEN HOLDING ApS (67-90%)
 *          └─ AKACIETORVET ApS
 *               └─ Akacietorvet 2 (BFE 250959)
 *                    └─ Ringkjøbing Landbobank 45 mio.
 *
 * Data findes allerede: underpanthavere hentes fra Tinglysningens offentlige
 * felt (§ 1 a, stk. 1) og eksponeres som `underpant` på hver pantebrevs-række.
 */
function lenderGraph(array $overrides = []): array
{
    $builder = new OwnershipGraphBuilder;

    $args = [
        'query' => $overrides['query'] ?? '29798486',
        'companyName' => $overrides['companyName'] ?? 'AKACIETORVET ApS',
        'structure' => $overrides['structure'] ?? ['ancestors' => [], 'subsidiaries' => []],
        'properties' => $overrides['properties'] ?? ['list' => [], 'usage' => []],
        'enrichment' => $overrides['enrichment'] ?? [],
        'expandedNodeIds' => $overrides['expandedNodeIds'] ?? [],
        'caps' => $overrides['caps'] ?? ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 120],
        'now' => $overrides['now'] ?? null,
        'layers' => $overrides['layers'] ?? ['owners', 'subsidiaries', 'properties', 'lenders'],
    ];

    return $builder->build(...$args);
}

/** Akacietorvets tre ejerlejligheder, som portefølje-rækker. */
function akaciePropertyList(): array
{
    return [
        ['matrikel_id' => '250959', 'address' => 'Akacietorvet 2', 'owner_cvr' => '29798486', 'is_matriculated' => true],
        ['matrikel_id' => '250960', 'address' => 'Akacietorvet 2A', 'owner_cvr' => '29798486', 'is_matriculated' => true],
    ];
}

/** Underpanthavere pr. BFE, som enrichment leverer dem. */
function akacieLenders(): array
{
    return [
        '250959' => [
            ['name' => 'Ringkjøbing Landbobank. Aktieselskab', 'cvr' => '37536814', 'amount' => 45_000_000],
            ['name' => 'Draupnir Investment Advisors A/S', 'cvr' => '35050027', 'amount' => 25_000_000],
        ],
        '250960' => [
            ['name' => 'Ringkjøbing Landbobank. Aktieselskab', 'cvr' => '37536814', 'amount' => 45_000_000],
        ],
    ];
}

it('hænger långivere under den ejendom de har pant i', function () {
    $g = lenderGraph([
        'properties' => ['list' => akaciePropertyList(), 'usage' => []],
        'enrichment' => ['lenders' => akacieLenders()],
    ]);

    $lenders = collect($g['nodes'])->where('kind', 'lender');

    expect($lenders)->toHaveCount(2)   // to UNIKKE långivere, ikke tre kanter
        ->and($lenders->pluck('label')->all())
        ->toContain('Ringkjøbing Landbobank. Aktieselskab', 'Draupnir Investment Advisors A/S');

    // Kanten går fra ejendom til långiver — ikke fra selskabet.
    $edge = collect($g['edges'])->firstWhere('to', 'lender:37536814');
    expect($edge['from'])->toStartWith('bfe:');
});

it('samler samme långiver til ÉN node på tværs af ejendomme', function () {
    // 🚨 Ringkjøbing har pant i BEGGE ejendomme. Uden dedup ville grafen få to
    // noder for samme bank — og en bruger ville tro der var to långivere.
    // Samme fælde som gældstallet: sampant ser ud som flere forhold.
    $g = lenderGraph([
        'properties' => ['list' => akaciePropertyList(), 'usage' => []],
        'enrichment' => ['lenders' => akacieLenders()],
    ]);

    $ringkjoebing = collect($g['nodes'])->where('id', 'lender:37536814');

    expect($ringkjoebing)->toHaveCount(1);

    // Men TO kanter — én pr. ejendom banken har pant i.
    $edges = collect($g['edges'])->where('to', 'lender:37536814');
    expect($edges)->toHaveCount(2);
});

it('viser beløbet på kanten, ikke på noden', function () {
    // Beløbet hører til RELATIONEN: samme bank kan have 45 mio. i én ejendom og
    // 2 mio. i en anden. Lægges det på noden, mister man den forskel.
    $g = lenderGraph([
        'properties' => ['list' => akaciePropertyList(), 'usage' => []],
        'enrichment' => ['lenders' => akacieLenders()],
    ]);

    $edge = collect($g['edges'])->first(fn ($e) => $e['from'] === 'bfe:250959' && $e['to'] === 'lender:37536814');

    expect($edge['label'])->toBe('45,0 mio.');
});

it('udenlandske långivere uden CVR får stadig en node', function () {
    // 🪤 Målt i prod: Landesbank Hessen-Thüringen (290 mio.), Kinnerton III DAC
    // (198 mio.) og SEB (168 mio.) har INTET CVR — de er ikke danske selskaber.
    // Nøgles der på CVR alene, forsvinder præcis de største kreditorer.
    $g = lenderGraph([
        'properties' => ['list' => [
            ['matrikel_id' => '158906', 'address' => 'Sundkrogsgade 11', 'owner_cvr' => '29798486', 'is_matriculated' => true],
        ], 'usage' => []],
        'enrichment' => ['lenders' => [
            '158906' => [['name' => 'Landesbank Hessen-Thüringen Girozentrale', 'cvr' => null, 'amount' => 290_482_500]],
        ]],
    ]);

    $lender = collect($g['nodes'])->firstWhere('kind', 'lender');

    expect($lender)->not->toBeNull()
        ->and($lender['label'])->toBe('Landesbank Hessen-Thüringen Girozentrale')
        ->and($lender['id'])->toBe('lender:landesbank-hessen-thuringen-girozentrale');
});

it('laget kan slås fra', function () {
    $g = lenderGraph([
        'properties' => ['list' => akaciePropertyList(), 'usage' => []],
        'enrichment' => ['lenders' => akacieLenders()],
        'layers' => ['owners', 'subsidiaries', 'properties'],
    ]);

    expect(collect($g['nodes'])->where('kind', 'lender'))->toBeEmpty()
        ->and(collect($g['edges'])->filter(fn ($e) => str_starts_with($e['to'], 'lender:')))->toBeEmpty();
});

it('kræver at ejendomslaget er tændt — en långiver kan ikke hænge i luften', function () {
    // Uden ejendomsnoder er der intet at hænge långiveren under. Laget må da
    // ikke producere forældreløse noder.
    $g = lenderGraph([
        'properties' => ['list' => akaciePropertyList(), 'usage' => []],
        'enrichment' => ['lenders' => akacieLenders()],
        'layers' => ['owners', 'subsidiaries', 'lenders'],
    ]);

    expect(collect($g['nodes'])->where('kind', 'lender'))->toBeEmpty();
});

it('ejendom uden pant får ingen långiver-noder', function () {
    $g = lenderGraph([
        'properties' => ['list' => akaciePropertyList(), 'usage' => []],
        'enrichment' => ['lenders' => []],
    ]);

    expect(collect($g['nodes'])->where('kind', 'lender'))->toBeEmpty();
});
