<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\PropertyExplore;

// Søge-fakes bruger '*/v1/property-explore' UDEN afsluttende '*'. Tilføjes
// et '*', matcher mønstret også '/v1/property-explore/export-link', og da
// Laravel vælger den første matchende fake, ville søge-faken stjæle
// export-kaldet. Samme fælde som i DebtSearchTest.

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

function fakeExploreResponse(?array $results = null): array
{
    return ['data' => [
        'results' => $results ?? [[
            'matrikel_id' => '123456', 'address' => 'Bredgade 40', 'postal_code' => '1260',
            'city' => 'København K', 'year_built' => 1960, 'area_building' => 1200, 'valuation' => 50_000_000, 'total_debt' => 12_000_000, 'weighted_rate' => 2.5,
        ]],
        'cursor' => null,
        'has_more' => false,
    ]];
}

it('kalder IKKE API uden geo-filter (viser hint)', function () {
    Http::fake();

    Livewire::test(PropertyExplore::class)
        ->set('yearBuiltFrom', 1950) // kun forfin, intet geo
        ->assertSet('missingGeo', true)
        ->assertSet('hasSearched', false);

    Http::assertNothingSent();
});

it('søger når postnr-filter sættes og viser resultater', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse())]);

    Livewire::test(PropertyExplore::class)
        ->set('postalCodeFrom', '1260')
        ->assertSet('missingGeo', false)
        ->assertSee('Bredgade 40');
});

it('sender POST til property-explore (ikke GET) — nested polygon-kompatibel transport', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse())]);

    Livewire::test(PropertyExplore::class)->set('postalCodeFrom', '2100');

    Http::assertSent(fn ($req) => $req->method() === 'POST' && str_contains($req->url(), 'property-explore'));
});

it('kommunekode alene tæller som geo-filter', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse())]);

    Livewire::test(PropertyExplore::class)
        ->set('municipalityCode', '101')
        ->assertSet('missingGeo', false)
        ->assertSee('Bredgade 40');
});

it('viser tom-tilstand når ingen ejendomme matcher', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse(results: []))]);

    Livewire::test(PropertyExplore::class)
        ->set('postalCodeFrom', '9999')
        ->assertSee('Ingen ejendomme matcher');
});

it('downloadCsv dispatcher download-event med signeret URL', function () {
    Http::fake([
        '*/v1/property-explore/export-link' => Http::response(['url' => 'https://api.test/property-explore.csv?sig=abc']),
        '*/v1/property-explore' => Http::response(fakeExploreResponse()), // set() trigger også search()
    ]);

    Livewire::test(PropertyExplore::class)
        ->set('postalCodeFrom', '2100')
        ->call('downloadCsv')
        ->assertDispatched('property-explore:download');
});

it('kvote-svar (429) viser kvote-besked', function () {
    Http::fake(['*/v1/property-explore' => Http::response([], 429)]);

    Livewire::test(PropertyExplore::class)
        ->set('postalCodeFrom', '2100')
        ->assertSet('quotaExceeded', true)
        ->assertSee('dagens søgekvote');
});

// ---- Polygon-korttegning (frontend) ----

it('setPolygon tæller som geo-filter og udløser søgning', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse())]);

    $polygon = [
        ['lat' => 55.60, 'lng' => 12.50],
        ['lat' => 55.75, 'lng' => 12.50],
        ['lat' => 55.75, 'lng' => 12.70],
    ];

    Livewire::test(PropertyExplore::class)
        ->call('setPolygon', $polygon)
        ->assertSet('missingGeo', false)
        ->assertSee('Bredgade 40');

    // Polygon sendes med i API-kaldet.
    Http::assertSent(fn ($req) => str_contains($req->url(), 'property-explore')
        && is_array($req->data()['polygon'] ?? null)
        && count($req->data()['polygon']) === 3);
});

it('polygon med under 3 punkter tæller IKKE som geo (frafiltreres)', function () {
    Http::fake();

    Livewire::test(PropertyExplore::class)
        ->call('setPolygon', [['lat' => 55.6, 'lng' => 12.5], ['lat' => 55.7, 'lng' => 12.6]]) // kun 2
        ->assertSet('missingGeo', true);

    Http::assertNothingSent();
});

it('clearPolygon rydder området', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse())]);

    $polygon = [['lat' => 55.60, 'lng' => 12.50], ['lat' => 55.75, 'lng' => 12.50], ['lat' => 55.75, 'lng' => 12.70]];

    Livewire::test(PropertyExplore::class)
        ->call('setPolygon', $polygon)
        ->assertSet('polygon', $polygon)
        ->call('clearPolygon')
        ->assertSet('polygon', [])
        ->assertSet('missingGeo', true);
});

// ---- UX: gensidigt udelukkende geo ----

it('at tegne polygon rydder tekst-geo (postnr/kommune)', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse())]);

    $polygon = [['lat' => 55.6, 'lng' => 12.5], ['lat' => 55.7, 'lng' => 12.5], ['lat' => 55.7, 'lng' => 12.7]];

    Livewire::test(PropertyExplore::class)
        ->set('postalCodeFrom', '2900')
        ->set('postalCodeTo', '2930')
        ->call('setPolygon', $polygon)
        ->assertSet('postalCodeFrom', null)   // ryddet
        ->assertSet('postalCodeTo', null)
        ->assertSet('polygon', $polygon);
});

it('at skrive postnr rydder en tegnet polygon (+ beder kortet rydde)', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse())]);

    $polygon = [['lat' => 55.6, 'lng' => 12.5], ['lat' => 55.7, 'lng' => 12.5], ['lat' => 55.7, 'lng' => 12.7]];

    Livewire::test(PropertyExplore::class)
        ->call('setPolygon', $polygon)
        ->assertSet('polygon', $polygon)
        ->set('postalCodeFrom', '2100')
        ->assertSet('polygon', [])            // polygon ryddet
        ->assertDispatched('property-explore:clear-map');
});

it('viser tinglyst gæld + rente-kolonner i tabellen', function () {
    Http::fake(['*/v1/property-explore' => Http::response(fakeExploreResponse())]);

    Livewire::test(PropertyExplore::class)
        ->set('postalCodeFrom', '1260')
        ->assertSee('Tinglyst gæld')
        ->assertSee('Rente')
        ->assertSee('12.000.000 kr.')
        ->assertSee('2,50 %');
});
