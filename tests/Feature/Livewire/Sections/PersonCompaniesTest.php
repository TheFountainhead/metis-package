<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\PersonCompanies;

// Flare 9097433: en transport-fejl gav en tom sektion med hasError=false —
// umulig at skelne fra "personen ejer ingen selskaber". Fejl skal VISES.
//
// CPR-opslag er lukket (9/10-2026): sektionen henter paa CPR og kan derfor
// aldrig faa data. Den kan stadig mountes direkte over /livewire/update, og
// skal saa vise FEJLTILSTANDEN — ikke "ingen selskaber" — uden et HTTP-kald.
it('viser fejltilstanden, ikke "ingen selskaber", naar CPR-opslaget er lukket — uden HTTP-kald', function () {
    Http::fake(['*' => Http::response(['data' => ['companies' => [['cvr' => '11111111', 'name' => 'HEMMELIG ApS']]]])]);

    Livewire::test(PersonCompanies::class, ['query' => '0101011234'])
        ->assertSet('hasError', true)
        ->assertSet('companies', [])
        ->assertSee('Selskabsdata kunne ikke hentes')
        ->assertDontSee('HEMMELIG');

    Http::assertNothingSent();
});
