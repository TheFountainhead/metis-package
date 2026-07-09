<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressSkraafoto;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeAnalysisWithCoordinates(?array $coordinates): array
{
    return [
        'data' => [
            'property' => [
                'coordinates' => $coordinates,
            ],
        ],
    ];
}

it('embeds the skraafoto viewer centered on the property (WGS84 lon,lat)', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithCoordinates([
            'lat' => 55.683331,
            'lng' => 12.590114,
        ])),
    ]);

    Livewire::test(AddressSkraafoto::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSet('lat', 55.683331)
        ->assertSet('lng', 12.590114)
        ->assertSeeHtml('https://skraafoto.dataforsyningen.dk/?center=12.590114%2C55.683331&amp;orientation=north')
        ->assertSee(__('Åbn i nyt vindue'));
});

it('accepts latitude/longitude key variants', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithCoordinates([
            'latitude' => 56.15674,
            'longitude' => 10.21076,
        ])),
    ]);

    Livewire::test(AddressSkraafoto::class, ['query' => 'Åboulevarden 1, 8000'])
        ->assertSet('lat', 56.15674)
        ->assertSet('lng', 10.21076);
});

it('renders nothing when coordinates are missing', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithCoordinates(null)),
    ]);

    Livewire::test(AddressSkraafoto::class, ['query' => 'Ukendt 1, 9999'])
        ->assertSet('lat', null)
        ->assertDontSeeHtml('<iframe');
});
