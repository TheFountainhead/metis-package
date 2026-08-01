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
 * Maalt paa Akacietorvet: Ringkjoebing Landbobank til 135 mio. i stedet for 45.
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
            ['name' => 'RINGKJØBING LANDBOBANK. AKTIESELSKAB', 'cvr' => '37536814', 'amount' => 45_000_000],
        ]),
    ]))]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '29798486']);

    expect($component->get('mortgages'))->toHaveCount(1);

    $html = $component->html();

    // Kreditor-kolonnen siger selskabet selv; panthaver-sektionen siger banken.
    expect($html)->toContain('Ringkjøbing Landbobank')
        ->and($html)->toContain('37536814');
});

it('samler samme laangiver paa tvaers af ejendomme — én gang, ikke én pr. ejendom', function () {
    // 🚨 Kernen. Samme pantebrev (doc-delt) haefter paa to ejendomme.
    // Uden dedup ville Ringkjoebing staa til 90 mio. i stedet for 45.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 45_000_000, 'Akacietorvet 2', [
            ['name' => 'RINGKJØBING LANDBOBANK. AKTIESELSKAB', 'cvr' => '37536814', 'amount' => 45_000_000],
        ], 'doc-delt'),
        mortgageWithLenders(2, 45_000_000, 'Akacietorvet 2A', [
            ['name' => 'RINGKJØBING LANDBOBANK. AKTIESELSKAB', 'cvr' => '37536814', 'amount' => 45_000_000],
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
            ['name' => 'RINGKJØBING LANDBOBANK. AKTIESELSKAB', 'cvr' => '37536814', 'amount' => 45_000_000],
        ], 'doc-a'),
        mortgageWithLenders(2, 5_000_000, 'Akacietorvet 2A', [
            ['name' => 'RINGKJØBING LANDBOBANK. AKTIESELSKAB', 'cvr' => '37536814', 'amount' => 5_000_000],
        ], 'doc-b'),
    ]))]);

    $lenders = Livewire::test(CompanyTinglysning::class, ['query' => '29798486'])->get('lenders');

    expect($lenders)->toHaveCount(1)
        ->and($lenders[0]['amount'])->toBe(50_000_000);
});

it('sorterer stoerste laangiver oeverst', function () {
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 25_000_000, 'Akacietorvet 2', [
            ['name' => 'Omega Finans ApS', 'cvr' => '43088483', 'amount' => 25_000_000],
        ], 'doc-a'),
        mortgageWithLenders(2, 45_000_000, 'Akacietorvet 2A', [
            ['name' => 'RINGKJØBING LANDBOBANK. AKTIESELSKAB', 'cvr' => '37536814', 'amount' => 45_000_000],
        ], 'doc-b'),
    ]))]);

    $lenders = Livewire::test(CompanyTinglysning::class, ['query' => '29798486'])->get('lenders');

    expect($lenders[0]['cvr'])->toBe('37536814')
        ->and($lenders[1]['cvr'])->toBe('43088483');
});

it('udenlandsk laangiver uden CVR faar stadig en raekke', function () {
    // 🪤 Maalt i prod: Landesbank Hessen-Thueringen (290 mio.), Kinnerton III DAC
    // og SEB har intet CVR. Noegles der paa CVR, forsvinder de stoerste
    // institutionelle kreditorer.
    Http::fake(['*tinglysning-overview*' => Http::response(tinglysningWithUnderpant([
        mortgageWithLenders(1, 290_482_500, 'Traneholmvej 2', [
            ['name' => 'Landesbank Hessen-Thüringen Girozentrale', 'cvr' => null, 'amount' => 290_482_500],
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
