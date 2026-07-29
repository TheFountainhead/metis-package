<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

it('upgrades type=name to type=cpr when input is a 10-digit cpr in person mode', function () {
    // Frederik 29/7: søgte på et CPR-nummer med "Person" valgt i type-vælgeren og
    // fik "Ingen resultater". Årsag: type-first-mode (search():222-227) omgår
    // SearchDetector helt og mapper 'person' hårdt til 'name', så CPR-tjekket på
    // :241 aldrig nås. Systemet ledte efter en PERSON VED NAVN "0212941239".
    //
    // Det er samme fejlklasse som resten af 28-29/7-arbejdet: "findes ikke" vist
    // hvor sandheden er "vi søgte slet ikke efter det".
    Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', '0212941239')
        ->call('search')
        ->assertSet('cprBlocked', true)
        ->assertSet('error', false);
});

it('upgrades a hyphenated cpr in person mode too', function () {
    Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', '021294-1239')
        ->call('search')
        ->assertSet('cprBlocked', true);
});

it('still treats a real name as a name search in person mode', function () {
    // Negativ kontrol: opgraderingen må ikke fange almindelige navne.
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', 'Frederik Larnæs')
        ->call('search')
        ->assertSet('cprBlocked', false);
});

it('does not treat an 8-digit cvr as a cpr in person mode', function () {
    // Kant: 8 cifre er et CVR, ikke et CPR. Regex'en kræver 10.
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', '28963610')
        ->call('search')
        ->assertSet('cprBlocked', false);
});
