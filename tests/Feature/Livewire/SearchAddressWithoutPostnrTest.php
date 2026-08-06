<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

beforeEach(function () {
    // Slå gating fra, så address-flowet ikke afbrydes af email-gaten.
    config(['metis.gating.enabled' => false]);
});

it('shows address suggestions instead of empty sections when postnr is missing', function () {
    Http::fake([
        '*/v1/map/autocomplete*' => Http::response([
            'data' => [
                ['tekst' => 'Travervænget 3, 2920 Charlottenlund'],
            ],
        ], 200),
    ]);

    Livewire::test(Search::class)
        ->set('query', 'travervænget 3')
        ->call('search')
        ->assertSet('error', true)
        ->assertSet('errorMessage', 'no_results')
        ->assertSet('suggestionType', 'address')
        ->assertSet('resultType', null); // sektionerne renderes IKKE

    // registry-api's property-analysis må aldrig kaldes for en ufuldstændig adresse
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/property/analysis'));
});

it('renders address sections when postnr is present', function () {
    Livewire::test(Search::class)
        ->set('query', 'Travervænget 3, 2920')
        ->call('search')
        ->assertSet('resultType', 'address')
        ->assertSet('error', false);
});
