<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\PersonProperties;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

// Task 3 (graph-filter-chips): PersonProperties::mount() switched from
// fetchPersonPropertyPortfolioByCpr() to the cached variant. Two mounts for the
// same CPR must hit the upstream endpoint only once.
it('uses the cached person-property-portfolio fetch, so a second mount is a cache hit', function () {
    Http::fake(['*/v1/person/property-portfolio' => Http::response(['data' => [
        'personal_properties' => [['address' => 'Bredgade 40']],
        'companies' => [],
        'summary' => [],
    ]])]);

    Livewire::test(PersonProperties::class, ['query' => '0101011234'])
        ->assertSet('personalProperties', [['address' => 'Bredgade 40']]);

    Livewire::test(PersonProperties::class, ['query' => '0101011234'])
        ->assertSet('personalProperties', [['address' => 'Bredgade 40']]);

    Http::assertSentCount(1);
});
