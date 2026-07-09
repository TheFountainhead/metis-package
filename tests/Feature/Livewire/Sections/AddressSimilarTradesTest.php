<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressSimilarTrades;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeAnalysisWithMatrikel(): array
{
    // Spejler prod-payloaden fra PropertyAnalysisController::formatAnalysis:
    // BFE-strengen hedder `matrikel_id`; `matrikel` er det fulde parcel-OBJEKT
    // fra Datafordeler (Flare #839462287: array givet til fetchSimilarSales(string)).
    return [
        'data' => [
            'property' => [
                'matrikel_id' => '2000138',
                'matrikel' => [
                    'ejerlav' => 'Sankt Annæ Øster Kvarter, København',
                    'matrikelnr' => '295',
                    'areal' => 1234,
                ],
            ],
        ],
    ];
}

it('resolves BFE from matrikel_id and fetches similar sales', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithMatrikel()),
        '*properties/2000138/similar-sales*' => Http::response([
            'data' => [
                'subject' => ['bfe' => '2000138'],
                'similar' => [
                    [
                        'id' => 1,
                        'address' => 'Bredgade 42',
                        'postal_code' => '1260',
                        'municipality_code' => '0101',
                        'total_area' => 5200,
                        'year_built' => 1898,
                        'sale_price' => 90_000_000,
                        'sale_date' => '2025-11-01',
                        'price_per_sqm' => 17308,
                        'official_valuation' => 84_000_000,
                    ],
                ],
                'count' => 1,
            ],
        ]),
    ]);

    Livewire::test(AddressSimilarTrades::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSet('totalCount', 1)
        ->assertSee('Bredgade 42');
});

it('does not pass the matrikel parcel object as BFE when matrikel_id is missing', function () {
    Http::fake([
        '*property/analysis*' => Http::response([
            'data' => [
                'property' => [
                    'matrikel_id' => null,
                    'matrikel' => ['ejerlav' => 'X', 'matrikelnr' => '1a'],
                ],
            ],
        ]),
    ]);

    // Før fixet: TypeError (array til fetchSimilarSales(string)) rapporteret til Flare.
    Livewire::test(AddressSimilarTrades::class, ['query' => 'Ukendt 1, 9999'])
        ->assertSet('similar', [])
        ->assertSet('totalCount', 0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'similar-sales'));
});
