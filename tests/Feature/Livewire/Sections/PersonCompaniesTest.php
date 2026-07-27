<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\PersonCompanies;

// Flare 9097433: en transport-fejl gav en tom sektion med hasError=false —
// umulig at skelne fra "personen ejer ingen selskaber". Fejl skal VISES.
it('sets the error state when the companies fetch fails on transport', function () {
    Http::fake(['*search-by-cpr*' => Http::failedConnection('cURL error 28: timeout')]);

    Livewire::test(PersonCompanies::class, ['query' => '0101011234'])
        ->assertSet('hasError', true)
        ->assertSee('Selskabsdata kunne ikke hentes');
});

it('renders the genuine empty state (not the error state) on a successful empty response', function () {
    Http::fake(['*search-by-cpr*' => Http::response(['data' => ['companies' => []]])]);

    Livewire::test(PersonCompanies::class, ['query' => '0101011234'])
        ->assertSet('hasError', false)
        ->assertDontSee('Selskabsdata kunne ikke hentes');
});
