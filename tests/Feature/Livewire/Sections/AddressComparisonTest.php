<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressComparison;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeAnalysisWithRent(string $propertyType = 'kontor', ?array $breakdown = null): array
{
    $estimate = [
        'avg_rent_per_sqm' => 1450,
        'median_rent_per_sqm' => 1450,
        'min_rent_per_sqm' => 1100,
        'max_rent_per_sqm' => 1800,
        'sample_count' => 12,
        'estimated_annual_rent' => 14_500_000,
        'property_type' => $propertyType,
    ];
    if ($breakdown) {
        $estimate['weighted'] = true;
        $estimate['breakdown'] = $breakdown;
    }

    return [
        'data' => [
            'property' => [
                'rental_estimate' => $estimate,
                'profitability' => [
                    'gross_yield' => 5.2,
                    'estimated_dscr' => 1.4,
                    'rent_source' => 'market_estimate',
                ],
            ],
        ],
    ];
}

it('labels scenario buttons as Udbudsleje and Skøn realiseret', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithRent('kontor')),
        '*property/compare*' => Http::response(['data' => null]),
    ]);

    Livewire::test(AddressComparison::class, ['query' => 'Tonsbakken 12, 2740'])
        ->assertSee('Udbudsleje')
        ->assertSee('Skøn realiseret')
        ->assertDontSee('Marked"', false);
});

it('shows lavere-signal confidence-badge for erhvervs-leje (kontor)', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithRent('kontor')),
        '*property/compare*' => Http::response(['data' => null]),
    ]);

    Livewire::test(AddressComparison::class, ['query' => 'Tonsbakken 12, 2740'])
        ->assertSee('Lavere signal')
        ->assertSee('erhvervsleje forhandles');
});

it('shows hoej-signal confidence-badge for bolig-leje', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithRent('bolig')),
        '*property/compare*' => Http::response(['data' => null]),
    ]);

    Livewire::test(AddressComparison::class, ['query' => 'Hovedgaden 14, 2200 København'])
        ->assertSee('Høj signal')
        ->assertSee('udbud ≈ realiseret');
});

it('shows mixed-signal badge for mixed-use property', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithRent('kontor', [
            ['type' => 'kontor', 'area' => 500, 'weight_pct' => 60, 'rent_per_sqm' => 1500, 'sample_count' => 8],
            ['type' => 'bolig', 'area' => 333, 'weight_pct' => 40, 'rent_per_sqm' => 1200, 'sample_count' => 4],
        ])),
        '*property/compare*' => Http::response(['data' => null]),
    ]);

    Livewire::test(AddressComparison::class, ['query' => 'Mixed 1, 1000 København'])
        ->assertSee('Blandet')
        ->assertSeeText('bolig + erhverv');
});

it('source-text refers to udbud not marked', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithRent('kontor')),
        '*property/compare*' => Http::response(['data' => null]),
    ]);

    Livewire::test(AddressComparison::class, ['query' => 'Tonsbakken 12, 2740'])
        ->assertSee('udbud')
        ->assertSee('Realiseret leje typisk');
});

/*
 * 🪤 ADRESSERNE HER BAERER POSTNUMMER MED VILJE (tilfoejet 20/8).
 *
 * Chokepunkt-guarden i `RegistryApi::resolveAddressAnalysis()` afviser en
 * adresse uden postnummer FOER kaldet — den kan ikke oploeses til én matrikel.
 * Fixturerne brugte 'Tonsbakken 12' som bekvem placeholder, men disse tests
 * handler om leje-grid og scenarier, ikke om adresse-opløsning. Uden
 * postnummer ville de teste guarden i stedet for deres eget emne.
 */
it('renders rent grid with correct base value', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithRent('kontor')),
        '*property/compare*' => Http::response(['data' => null]),
    ]);

    Livewire::test(AddressComparison::class, ['query' => 'Tonsbakken 12, 2740'])
        ->assertSet('rentalEstimate.avg_rent_per_sqm', 1450);
});

it('shows Annonce-baseret source badges for market estimates (F8)', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithRent('kontor')),
        '*property/compare*' => Http::response(['data' => null]),
    ]);

    Livewire::test(AddressComparison::class, ['query' => 'Tonsbakken 12, 2740'])
        ->assertSee(__('Annonce-baseret'))
        ->assertSee(__('Annonce-baseret leje'));
});

it('shows Indberettet leje badge when rent comes from user input (F8)', function () {
    Http::fake([
        '*property/analysis*' => Http::response([
            'data' => [
                'property' => [
                    'rental_estimate' => null,
                    'profitability' => [
                        'gross_yield' => 6.1,
                        'estimated_dscr' => 1.8,
                        'rent_source' => 'user_input',
                    ],
                ],
            ],
        ]),
        '*property/compare*' => Http::response(['data' => null]),
    ]);

    Livewire::test(AddressComparison::class, ['query' => 'Indberettet 2, 8000 Aarhus'])
        ->assertSee(__('Indberettet leje'))
        ->assertDontSee(__('Annonce-baseret'));
});
