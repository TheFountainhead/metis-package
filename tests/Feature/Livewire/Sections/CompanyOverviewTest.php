<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyOverview;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeCompanyInfo(array $overrides = []): array
{
    return array_merge([
        'cvr' => '28963610',
        'name' => 'Mimo Invest ApS',
        'employees' => 4,
        'financials' => [
            ['year' => '2024', 'equity' => 121_363_585, 'assets' => 585_110_069, 'profit_loss' => -12_785_619],
            ['year' => '2023', 'equity' => 134_149_204, 'assets' => 612_845_330, 'profit_loss' => 8_100_000],
            ['year' => '2022', 'equity' => 126_049_204, 'assets' => 598_122_111, 'profit_loss' => 5_400_000],
        ],
    ], $overrides);
}

function fakePortfolio(array $overrides = []): array
{
    return array_merge([
        'owner_type' => 'company',
        'owner_cvr' => '28963610',
        'property_count' => 3,
        'total_valuation' => 65_000_000,
        'properties' => [
            [
                'matrikel_id' => '1234567',
                'address' => 'Tonsbakken 12',
                'postal_code' => '2740',
                'city' => 'Skovlunde',
                'latitude' => 55.7234,
                'longitude' => 12.3923,
                'building_usage' => 'Erhverv',
                'valuation' => 30_000_000,
                'total_debt' => 22_000_000,
            ],
            [
                'matrikel_id' => '2345678',
                'address' => 'Hovedgaden 14',
                'postal_code' => '2200',
                'city' => 'København N',
                'latitude' => 55.6900,
                'longitude' => 12.5500,
                'building_usage' => 'Bolig',
                'valuation' => 15_000_000,
                'total_debt' => 9_000_000,
            ],
            [
                'matrikel_id' => '3456789',
                'address' => 'Industrivej 1',
                'postal_code' => '2630',
                'city' => 'Taastrup',
                'latitude' => 55.6500,
                'longitude' => 12.2900,
                'building_usage' => 'Erhverv',
                'valuation' => 20_000_000,
                'total_debt' => 14_000_000,
            ],
        ],
    ], $overrides);
}

it('mounts and exposes KPI tiles from portfolio + financials', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo()]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => fakePortfolio()]]),
    ]);

    Livewire::test(CompanyOverview::class, ['query' => '28963610'])
        ->assertSet('hasError', false)
        ->assertSet('propertyCount', 3)
        ->assertSet('totalValuation', 65_000_000)
        ->assertSet('totalDebt', 45_000_000)
        ->assertSet('employees', 4);
});

it('aggregates building_usage_distribution for pie chart', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo()]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => fakePortfolio()]]),
    ]);

    $component = Livewire::test(CompanyOverview::class, ['query' => '28963610']);
    $distribution = $component->get('usageDistribution');

    expect($distribution)->toHaveKey('Erhverv');
    expect($distribution['Erhverv'])->toBe(2);
    expect($distribution['Bolig'])->toBe(1);
});

it('maps raw BBR usage codes to readable categories (not the raw code)', function () {
    // Prod BBR-data leverer numeriske koder — ikke tekst. Diagrammet må aldrig
    // vise "321"/"322"/"140"; det skal gruppere til brede kategorier og lægge
    // koder for samme anvendelse sammen (fx 321+329 = Kontor).
    $portfolio = fakePortfolio([
        'property_count' => 6,
        'properties' => [
            ['building_usage' => '140', 'address' => 'A', 'postal_code' => '2200'], // Bolig
            ['building_usage' => '120', 'address' => 'B', 'postal_code' => '2200'], // Bolig
            ['building_usage' => '321', 'address' => 'C', 'postal_code' => '1050'], // Kontor
            ['building_usage' => '329', 'address' => 'D', 'postal_code' => '1050'], // Kontor
            ['building_usage' => '322', 'address' => 'E', 'postal_code' => '1050'], // Butik
            ['building_usage' => '323', 'address' => 'F', 'postal_code' => '2630'], // Lager
        ],
    ]);

    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo()]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => $portfolio]]),
    ]);

    $distribution = Livewire::test(CompanyOverview::class, ['query' => '28963610'])
        ->get('usageDistribution');

    // Ingen rå koder tilbage som labels.
    foreach (array_keys($distribution) as $label) {
        expect(is_numeric($label))->toBeFalse();
    }

    expect($distribution['Bolig'])->toBe(2);   // 140 + 120
    expect($distribution['Kontor'])->toBe(2);  // 321 + 329
    expect($distribution['Butik'])->toBe(1);   // 322
    expect($distribution['Lager'])->toBe(1);   // 323

    // Sorteret faldende, så de største typer står først i legenden.
    expect(array_values($distribution))->toBe([2, 2, 1, 1]);
});

it('exposes 3-year financial history in chronological order (oldest → newest)', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo()]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => fakePortfolio()]]),
    ]);

    $component = Livewire::test(CompanyOverview::class, ['query' => '28963610']);
    $history = $component->get('financialHistory');

    expect($history)->toHaveCount(3);
    expect($history[0]['year'])->toBe('2022');
    expect($history[1]['year'])->toBe('2023');
    expect($history[2]['year'])->toBe('2024');
    expect($history[2]['equity'])->toBe(121_363_585);
});

it('exposes map_pins with lat/lng/address for Leaflet', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo()]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => fakePortfolio()]]),
    ]);

    $component = Livewire::test(CompanyOverview::class, ['query' => '28963610']);
    $pins = $component->get('mapPins');

    expect($pins)->toHaveCount(3);
    expect($pins[0])->toHaveKeys(['lat', 'lng', 'address']);
    expect($pins[0]['lat'])->toBe(55.7234);
});

it('skips properties without coordinates from map_pins', function () {
    $portfolio = fakePortfolio();
    $portfolio['properties'][1]['latitude'] = null;
    $portfolio['properties'][1]['longitude'] = null;

    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo()]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => $portfolio]]),
    ]);

    $component = Livewire::test(CompanyOverview::class, ['query' => '28963610']);
    expect($component->get('mapPins'))->toHaveCount(2);
});

it('handles empty portfolio gracefully (no properties → no map, no pie chart)', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo()]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => fakePortfolio(['property_count' => 0, 'properties' => [], 'total_valuation' => 0])]]),
    ]);

    Livewire::test(CompanyOverview::class, ['query' => '28963610'])
        ->assertSet('propertyCount', 0)
        ->assertSet('mapPins', [])
        ->assertSet('usageDistribution', []);
});

it('handles missing financials gracefully (no graph)', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo(['financials' => []])]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => fakePortfolio()]]),
    ]);

    Livewire::test(CompanyOverview::class, ['query' => '28963610'])
        ->assertSet('financialHistory', []);
});

it('renders component with section-title', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => fakeCompanyInfo()]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => fakePortfolio()]]),
    ]);

    Livewire::test(CompanyOverview::class, ['query' => '28963610'])
        ->assertSee('Mimo Invest ApS')
        ->assertSee('Tonsbakken 12');
});
