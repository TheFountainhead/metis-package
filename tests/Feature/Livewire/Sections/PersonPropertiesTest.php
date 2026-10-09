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

// CPR-opslag er lukket (9/10-2026). PersonProperties henter paa CPR
// (fetchPersonPropertyPortfolioByCprCached) og kan stadig mountes direkte over
// /livewire/update. Den skal saa vise FEJLTILSTANDEN — et lukket opslag maa
// ikke rendere som "0 ejendomme" — og den maa ikke kalde registry-api, heller
// ikke ved et gentaget mount (den tidligere cache-test her).
it('viser fejltilstanden og kalder intet, naar CPR-opslaget er lukket', function () {
    Http::fake(['*' => Http::response(['data' => [
        'personal_properties' => [['address' => 'Bredgade 40']],
        'companies' => [],
        'summary' => [],
    ]])]);

    foreach ([1, 2] as $_) {
        Livewire::test(PersonProperties::class, ['query' => '0101011234'])
            ->assertSet('hasError', true)
            ->assertSet('personalProperties', [])
            ->assertDontSee('Bredgade 40');
    }

    Http::assertNothingSent();
});
