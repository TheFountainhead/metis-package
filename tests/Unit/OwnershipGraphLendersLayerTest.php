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
 *                    └─ den finansierende bank
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

/**
 * Underpanthavere pr. BFE, som enrichment leverer dem.
 *
 * Opdigtede banker og beløb. Testene handler om grafens MEKANIK — dedup af
 * samme långiver på tværs af ejendomme, og at beløbet hører til kanten — og
 * den mekanik er uafhængig af hvem der rent faktisk har pant hvor. Et fixture
 * der parrer en rigtig bank med et rigtigt beløb på en rigtig ejendom ville
 * læse som en påstand om virkeligheden uden at teste mere.
 */
function akacieLenders(): array
{
    return [
        '250959' => [
            ['name' => 'Testbanken A/S', 'cvr' => '10000001', 'amount' => 45_000_000],
            ['name' => 'Anden Testbank ApS', 'cvr' => '10000002', 'amount' => 25_000_000],
        ],
        '250960' => [
            ['name' => 'Testbanken A/S', 'cvr' => '10000001', 'amount' => 45_000_000],
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
        ->toContain('Testbanken A/S', 'Anden Testbank ApS');

    // Kanten går fra ejendom til långiver — ikke fra selskabet.
    $edge = collect($g['edges'])->firstWhere('to', 'lender:10000001');
    expect($edge['from'])->toStartWith('bfe:');
});

it('samler samme långiver til ÉN node på tværs af ejendomme', function () {
    // 🚨 Testbanken har pant i BEGGE ejendomme. Uden dedup ville grafen få to
    // noder for samme bank — og en bruger ville tro der var to långivere.
    // Samme fælde som gældstallet: sampant ser ud som flere forhold.
    $g = lenderGraph([
        'properties' => ['list' => akaciePropertyList(), 'usage' => []],
        'enrichment' => ['lenders' => akacieLenders()],
    ]);

    $testbanken = collect($g['nodes'])->where('id', 'lender:10000001');

    expect($testbanken)->toHaveCount(1);

    // Men TO kanter — én pr. ejendom banken har pant i.
    $edges = collect($g['edges'])->where('to', 'lender:10000001');
    expect($edges)->toHaveCount(2);
});

it('viser beløbet på kanten, ikke på noden', function () {
    // Beløbet hører til RELATIONEN: samme bank kan have 45 mio. i én ejendom og
    // 2 mio. i en anden. Lægges det på noden, mister man den forskel.
    $g = lenderGraph([
        'properties' => ['list' => akaciePropertyList(), 'usage' => []],
        'enrichment' => ['lenders' => akacieLenders()],
    ]);

    $edge = collect($g['edges'])->first(fn ($e) => $e['from'] === 'bfe:250959' && $e['to'] === 'lender:10000001');

    expect($edge['label'])->toBe('45,0 mio.');
});

it('udenlandske långivere uden CVR får stadig en node', function () {
    // 🪤 Målt i prod: de TRE største institutionelle kreditorer er udenlandske
    // og har INTET CVR. Nøgles der på CVR alene, forsvinder præcis dem.
    //
    // Navnet her er opdigtet, men bærer omlyd med vilje: slug-stien skal
    // transliterere ü → u. Beløb og adresse er neutrale — de tester intet, og
    // et rigtigt navn parret med et rigtigt beløb på en rigtig adresse læser
    // som en påstand om virkeligheden.
    $g = lenderGraph([
        'properties' => ['list' => [
            ['matrikel_id' => '158906', 'address' => 'Testvej 1', 'owner_cvr' => '29798486', 'is_matriculated' => true],
        ], 'usage' => []],
        'enrichment' => ['lenders' => [
            '158906' => [['name' => 'Tysk Girozentrale München', 'cvr' => null, 'amount' => 10_000_000]],
        ]],
    ]);

    $lender = collect($g['nodes'])->firstWhere('kind', 'lender');

    expect($lender)->not->toBeNull()
        ->and($lender['label'])->toBe('Tysk Girozentrale München')
        ->and($lender['id'])->toBe('lender:tysk-girozentrale-munchen');
});

it('skriver långiverens navn som resten af platformen, ikke i VERSALER', function () {
    // 🪤 `LegalUnitName` kommer fra CVR, som gemmer navne i VERSALER — pinnet
    // som API-kontrakt i registry-api's CompanyTinglysningOverviewTest:492.
    // Grafen sendte navnet uændret videre, så samme bank stod i VERSALER i
    // grafen og i normal skrift i panthaver-tabellen — på samme side.
    //
    // Selskabsformen er en juridisk betegnelse: ApS må ikke blive til Aps.
    // Opdigtede navne — det er skrivemåden der testes, ikke hvem der låner ud.
    $g = lenderGraph([
        'properties' => ['list' => [
            ['matrikel_id' => '250959', 'address' => 'Akacietorvet 2', 'owner_cvr' => '29798486', 'is_matriculated' => true],
        ], 'usage' => []],
        'enrichment' => ['lenders' => [
            '250959' => [
                ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => 45_000_000],
                ['name' => 'ANDEN TESTBANK APS', 'cvr' => '10000002', 'amount' => 25_000_000],
            ],
        ]],
    ]);

    $labels = collect($g['nodes'])->where('kind', 'lender')->pluck('label')->all();

    expect($labels)->toContain('Testbanken. Aktieselskab')
        ->and($labels)->toContain('Anden Testbank ApS');
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
