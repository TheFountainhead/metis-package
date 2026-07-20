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
