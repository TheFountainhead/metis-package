<?php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyOverview;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

it('formats JEUDAN-scale debt as mia, not a 7-digit mio number', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'JEUDAN A/S', 'employees' => 100]]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => [
            'owner_type' => 'company', 'owner_cvr' => '14246045', 'property_count' => 972,
            'total_valuation' => 18_000_000_000, // 18 mia
            'properties' => [[
                'matrikel_id' => '1', 'address' => 'A', 'postal_code' => '1260', 'city' => 'Kbh',
                'building_usage' => '321', 'valuation' => 18_000_000_000, 'total_debt' => 24_353_800_000_00 / 100,
            ]],
        ]]]),
    ]);

    $html = Livewire::test(CompanyOverview::class, ['query' => '14246045'])->html();

    // Stort beløb vist som mia — ikke det ulæselige "24.353,8 mio"
    expect($html)->toContain('mia. kr');
    // whitespace-nowrap + text-xl anvendt (ikke text-2xl overflow)
    expect($html)->toContain('whitespace-nowrap');
    expect($html)->not->toContain('text-2xl');
});

it('uses total_count (full portfolio) not page-count for property KPI', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'JEUDAN A/S']]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => [
            'owner_type' => 'company', 'owner_cvr' => '14246045',
            'property_count' => 500,   // kun side
            'total_count' => 649,      // fuldt antal
            'total_valuation' => 1_000_000_000,
            'properties' => array_fill(0, 500, ['building_usage' => '321', 'total_debt' => 0]),
        ]]]),
    ]);

    Livewire::test(CompanyOverview::class, ['query' => '14246045'])
        ->assertSet('propertyCount', 649); // ikke 500
});

it('does not let a bare dash pass for "no debt" when properties were never examined', function () {
    // AKACIETORVET: KPI'en viste "Tinglyst gaeld —". Den bare streg ser ud som
    // "ingen gaeld", hvor sandheden var at 3 af 4 ejendomme aldrig var
    // undersoegt og baar 79,9 mio.
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'AKACIETORVET ApS']]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => [
            'owner_type' => 'company', 'owner_cvr' => '29798486',
            'total_count' => 4, 'total_valuation' => 0,
            'properties' => [[
                'matrikel_id' => '2262451', 'address' => 'Akacietorvet 2',
                'postal_code' => '3520', 'city' => 'Farum', 'total_debt' => 0,
            ]],
            'coverage' => [
                'properties_total' => 4, 'properties_answered' => 1,
                'properties_pending' => 0, 'properties_blocked' => 3,
                'all_properties_answered' => false,
                'oldest_answer_at' => '2026-07-13 04:55:55',
            ],
        ]]]),
    ]);

    $html = Livewire::test(CompanyOverview::class, ['query' => '29798486'])->html();

    expect($html)->toContain('1 af 4 ejendomme undersøgt')
        ->and($html)->toContain('3 mangler adressedata');
});

it('stays quiet when every property has been examined', function () {
    // Forbeholdet maa ikke staa paa hvert opslag — saa mister det sin betydning.
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'Komplet ApS']]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => [
            'owner_type' => 'company', 'owner_cvr' => '11111111',
            'total_count' => 2, 'total_valuation' => 5_000_000,
            'properties' => [[
                'matrikel_id' => '1', 'address' => 'A', 'postal_code' => '1000',
                'city' => 'Kbh', 'total_debt' => 100_000,
            ]],
            'coverage' => [
                'properties_total' => 2, 'properties_answered' => 2,
                'properties_pending' => 0, 'properties_blocked' => 0,
                'all_properties_answered' => true,
                'oldest_answer_at' => '2026-07-20 10:00:00',
            ],
        ]]]),
    ]);

    $html = Livewire::test(CompanyOverview::class, ['query' => '11111111'])->html();

    expect($html)->not->toContain('ejendomme undersøgt');
});

it('discloses coverage even when the company has no portfolio to show', function () {
    // Den farligste tomhed: portfolio=null gav en bar streg uden forbehold.
    // coverage ligger ved siden af portfolio i svaret, ikke inde i den.
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'Tom ApS']]]),
        '*property-portfolio*' => Http::response(['data' => [
            'portfolio' => null,
            'coverage' => [
                'properties_total' => 3, 'properties_answered' => 0,
                'properties_pending' => 3, 'properties_blocked' => 0,
                'all_properties_answered' => false,
                'oldest_answer_at' => null,
            ],
        ]]),
    ]);

    $html = Livewire::test(CompanyOverview::class, ['query' => '22222222'])->html();

    expect($html)->toContain('0 af 3 ejendomme undersøgt')
        ->and($html)->toContain('3 er endnu ikke hentet');
});
