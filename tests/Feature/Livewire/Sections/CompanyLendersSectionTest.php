<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyTinglysning;

beforeEach(function () {
    // fetchCompanyTinglysningOverview() cacher paa cvr+filters — uden flush
    // laeser test 2 test 1's svar.
    Cache::flush();

    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

/**
 * Panthavere paa selskabssiden.
 *
 * 🎯 API'et har sendt `underpant` paa hver pantebrevs-raekke hele tiden
 * (MortgageRowResource:47), men visningen brugte det aldrig. Resultatet: siden
 * viste "AKACIETORVET ApS" som kreditor paa 76,8 mio. i ejerpantebreve — teknisk
 * sandt, men vildledende. De faktiske laangivere stod i et felt vi ikke laeste.
 *
 * Gaeldsrapporten (registry-api #217) viser dem allerede. Denne sektion bringer
 * platformen paa hoejde med rapporten.
 *
 * 🚨 Underpant arver sampant-faelden: samme pantebrev kan haefte paa flere
 * ejendomme, saa en naiv aggregering taeller laangiveren én gang pr. ejendom.
 * Maalt i prod: én bank stod til 135 mio. i stedet for 45.
 */
function tinglysningWithUnderpant(array $mortgages): array
{
    return [
        'company' => ['cvr' => '29798486', 'name' => 'AKACIETORVET ApS'],
        'tree_meta' => [
            'result_kind' => 'ok',
            'total_descendant_companies' => 0,
            'total_properties' => 3,
            'total_mortgages' => count($mortgages),
            'total_principal_amount' => collect($mortgages)->sum('principal_amount'),
            'weighted_ltv' => null,
            'tree_depth' => 0,
            'applied_tree_depth' => 1,
        ],
        'tier_breakdown' => [],
        'mortgages_added' => $mortgages,
        'streaming' => ['complete' => true, 'cursor' => null, 'total_expected' => count($mortgages), 'delivered_so_far' => count($mortgages)],
    ];
}

function mortgageWithLenders(int $id, int $kr, string $address, array $lenders, ?string $doc = null): array
{
    return [
        'id' => $id,
        'property_id' => 3330680 + $id,
        'address' => $address,
        'bfe' => '25095'.$id,
        'owner_company' => ['cvr' => '29798486', 'name' => 'AKACIETORVET ApS'],
        'tier_depth' => 0,
        'mortgage_type' => 'ejerpantebrev',
        'creditor' => 'AKACIETORVET ApS',
        'debitor' => null,
        'priority' => 10,
        'principal_amount' => $kr * 100,
        'registration_date' => '2026-03-17',
        'is_active' => true,
        'is_sampant' => false,
        'is_afgiftspantebrev' => false,
        'dokument_identifikator' => $doc ?? ('doc-'.$id),
        'underpant' => $lenders,
        'ltv' => ['value' => null, 'method' => 'unavailable'],
    ];
}

it('viser de faktiske laangivere, ikke kun kreditor-kolonnen', function () {
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 45_000_000, 'Akacietorvet 2', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '45000000'],
        ]),
    ]))]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '29798486']);

    expect($component->get('mortgages'))->toHaveCount(1);

    $html = $component->html();

    // Kreditor-kolonnen siger selskabet selv; panthaver-sektionen siger banken.
    expect($html)->toContain('Testbanken')
        ->and($html)->toContain('10000001');
});

it('samler samme laangiver paa tvaers af ejendomme — én gang, ikke én pr. ejendom', function () {
    // 🚨 Kernen. Samme pantebrev (doc-delt) haefter paa to ejendomme.
    // Uden dedup ville banken staa til 90 mio. i stedet for 45.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 45_000_000, 'Akacietorvet 2', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '45000000'],
        ], 'doc-delt'),
        mortgageWithLenders(2, 45_000_000, 'Akacietorvet 2A', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '45000000'],
        ], 'doc-delt'),
    ]))]);

    $lenders = Livewire::test(CompanyTinglysning::class, ['query' => '29798486'])->get('lenders');

    expect($lenders)->toHaveCount(1)
        ->and($lenders[0]['amount'])->toBe(45_000_000);
});

it('laegger FORSKELLIGE pantebreve fra samme laangiver sammen', function () {
    // To selvstaendige dokumenter hos samme bank ER to laan. De skal summeres.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 45_000_000, 'Akacietorvet 2', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '45000000'],
        ], 'doc-a'),
        mortgageWithLenders(2, 5_000_000, 'Akacietorvet 2A', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '5000000'],
        ], 'doc-b'),
    ]))]);

    $lenders = Livewire::test(CompanyTinglysning::class, ['query' => '29798486'])->get('lenders');

    expect($lenders)->toHaveCount(1)
        ->and($lenders[0]['amount'])->toBe(50_000_000);
});

it('sorterer stoerste laangiver oeverst', function () {
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 25_000_000, 'Akacietorvet 2', [
            ['name' => 'Anden Testbank ApS', 'cvr' => '10000002', 'amount' => '25000000'],
        ], 'doc-a'),
        mortgageWithLenders(2, 45_000_000, 'Akacietorvet 2A', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '45000000'],
        ], 'doc-b'),
    ]))]);

    $lenders = Livewire::test(CompanyTinglysning::class, ['query' => '29798486'])->get('lenders');

    expect($lenders[0]['cvr'])->toBe('10000001')
        ->and($lenders[1]['cvr'])->toBe('10000002');
});

it('udenlandsk laangiver uden CVR faar stadig en raekke', function () {
    // 🪤 Maalt i prod: Landesbank Hessen-Thueringen (290 mio.), Kinnerton III DAC
    // og SEB har intet CVR. Noegles der paa CVR, forsvinder de stoerste
    // institutionelle kreditorer.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 290_482_500, 'Traneholmvej 2', [
            ['name' => 'Landesbank Hessen-Thüringen Girozentrale', 'cvr' => null, 'amount' => '290482500'],
        ]),
    ]))]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '29798486']);
    $lenders = $component->get('lenders');

    expect($lenders)->toHaveCount(1)
        ->and($lenders[0]['name'])->toBe('Landesbank Hessen-Thüringen Girozentrale')
        ->and($lenders[0]['cvr'])->toBeNull()
        ->and($component->html())->toContain('Landesbank Hessen-Thüringen');
});

it('viser ingen sektion naar der ikke er underpant', function () {
    // Et selskab med almindelige realkreditpantebreve har ingen underpanthavere.
    // Sektionen maa da ikke staa tom og antyde manglende data.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 7_751_000, 'Akacietorvet 2', []),
    ]))]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '29798486']);

    expect($component->get('lenders'))->toBe([])
        ->and($component->html())->not->toContain('Panthavere');
});

it('viser samme beloeb som pantebrevs-tabellen — ikke 100x for lavt', function () {
    // 🚨 BLOKERENDE FEJL FANGET I REVIEW 2/8. Bladen dividerede med 100.
    //
    // De to felter har FORSKELLIG enhed, og det er pinnet i registry-api:
    //   principal_amount  = OERER  (Mortgage.php:15 — "BeloebVaerdi × 100")
    //   underpant.amount  = KRONER (StreamTinglysningMortgages.php:379 — raa
    //                       gennemstilling af BeloebVaerdi, ingen ×100)
    // API-kontrakten er pinnet i CompanyTinglysningOverviewTest:494:
    // et 45 mio. pantebrev giver underpant[0]['amount'] === '45000000'.
    //
    // Skaden var kundevendt og synlig: SAMME pantebrev stod som 45.000.000 kr
    // i Pantebreve-tabellen og 450.000 kr i Panthavere-tabellen paa samme side.
    //
    // 🪤 Testene var groenne fordi fixturen indkodede samme forveksling som
    // koden: 'principal_amount' => $kr * 100 men 'amount' => $kr. Fixture og
    // implementering bekraeftede hinanden. Derfor asserter denne test det
    // RENDEREDE tal, ikke det beregnede.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 45_000_000, 'Akacietorvet 2', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '45000000'],
        ]),
    ]))]);

    $html = Livewire::test(CompanyTinglysning::class, ['query' => '29798486'])->html();

    expect($html)->toContain('45.000.000')
        ->and($html)->not->toContain('450.000 kr');
});

it('CVR-linket peger paa en type lookup-viewet faktisk kender', function () {
    // 🚨 FANGET I REVIEW 2/8: linket brugte type=company. lookup.blade.php
    // brancher kun paa cvr|cpr|person|address, saa 'company' ramte ingen gren
    // og gav en TOM side uden fejlbesked. Stub-ruten i beforeEach beviste kun
    // at route() ikke kastede.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 45_000_000, 'Akacietorvet 2', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '45000000'],
        ]),
    ]))]);

    $html = Livewire::test(CompanyTinglysning::class, ['query' => '29798486'])->html();

    expect($html)->toContain('/lookup/cvr/10000001')
        ->and($html)->not->toContain('/lookup/company/');
});

it('foerste IKKE-NULL CVR vinder, uanset raekkefoelge', function () {
    // Samme laangiver kan optraede baade med og uden CVR — en personregistreret
    // eller udenlandsk panthaver giver null. Blev CVR'et sat ved oprettelsen,
    // afhang linket af hvilken raekke der kom foerst.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 20_000_000, 'Akacietorvet 2', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => null, 'amount' => '20000000'],
        ], 'doc-a'),
        mortgageWithLenders(2, 25_000_000, 'Akacietorvet 2A', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '25000000'],
        ], 'doc-b'),
    ]))]);

    $lenders = Livewire::test(CompanyTinglysning::class, ['query' => '29798486'])->get('lenders');

    expect($lenders)->toHaveCount(1)
        ->and($lenders[0]['cvr'])->toBe('10000001')
        ->and($lenders[0]['amount'])->toBe(45_000_000);
});

it('skjuler sektionen mens streamingen loeber — et halvt tal ser afsluttet ud', function () {
    // Pantebrevs-tabellen har spinner + skeleton-raekker; panthaver-tabellen
    // havde ingen af delene. Paa en portefoelje over stream_page_size ville en
    // bruger se et for lavt beloeb pr. panthaver uden nogen indikation af at
    // det stadig voksede.
    $body = tinglysningWithUnderpant([
        mortgageWithLenders(1, 45_000_000, 'Akacietorvet 2', [
            ['name' => 'TESTBANKEN. AKTIESELSKAB', 'cvr' => '10000001', 'amount' => '45000000'],
        ]),
    ]);
    $body['streaming'] = ['complete' => false, 'cursor' => 'næste', 'total_expected' => 120, 'delivered_so_far' => 1];

    Http::fake(['*tinglysning-overview*' => Http::response($body)]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '29798486']);

    // Dataen ER der — den vises bare ikke foer den er komplet.
    expect($component->get('lenders'))->toHaveCount(1)
        ->and($component->get('streaming'))->toBeTrue()
        ->and($component->html())->not->toContain('Panthavere');
});
