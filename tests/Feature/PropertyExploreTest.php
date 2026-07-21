<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\PropertyExplore;

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
            'city' => 'København K', 'year_built' => 1960, 'area_building' => 1200, 'valuation' => 50_000_000,
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
