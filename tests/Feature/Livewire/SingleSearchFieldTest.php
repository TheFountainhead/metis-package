<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

uses(RefreshDatabase::class);

/**
 * ÉT soegefelt. Ingen type-valg foer soegningen.
 *
 * 🚨 BROWSER-FUND 6/8: #146 og #147 fjernede tre mode-BYPASS i logikken, og
 * alle tests var groenne. Men forsiden var stadig en tre-vejs menu — "Hvad
 * vil du soege paa? Person / Selskab / Ejendom" — med soegefeltet gemt bag
 * det valg. Brugeren kunne ikke bare skrive.
 *
 * 🔑 HVORFOR TESTENE IKKE FANGEDE DET: de satte `->set('searchMode', 'person')`
 * og verificerede at ADFAERDEN var mode-uafhaengig. Det var sandt. Men de
 * sprang over spoergsmaalet om brugeren overhovedet SKAL vaelge. Testen
 * antog den skaerm den skulle have udfordret.
 *
 * Fundet ved at hente den server-renderede HTML fra prod — ikke af en test.
 * Adfaerden bestemmes i Blade-gaten `@if($searchMode === '')`, ikke i
 * performSearch(). Grep `resources/`, ikke kun `src/`.
 *
 * 🎯 Resights-lektionen: ét soegefelt uden modes. Vores opdeling gav CPR-
 * fejlen (metis #145) og to andre prod-fejl. Disambiguering hoerer hjemme
 * EFTER soegningen — brugeren skriver hvad de har, typen fremgaar af svaret.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []])]);
});

it('🚨 forsiden viser soegefeltet med det samme — intet type-valg', function () {
    Livewire::test(Search::class)
        ->assertSee('Søg person, virksomhed eller adresse...', false)
        ->assertDontSee('Hvad vil du søge på?', false);
});

it('🚨 ?mode= i URL kan ikke laengere skjule soegefeltet', function () {
    // Gamle bogmaerker og sidebar-links baerer stadig ?mode=person. De maa
    // ikke genskabe den laaste skaerm.
    foreach (['person', 'company', 'address'] as $mode) {
        Livewire::withQueryParams(['mode' => $mode])
            ->test(Search::class)
            ->assertSee('Søg person, virksomhed eller adresse...', false)
            ->assertDontSee('Hvad vil du søge på?', false);
    }
});

it('en soegning virker uden at der er valgt en type', function () {
    Http::fake(['*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
        'name' => 'Test A/S', 'cvr' => '35050027',
    ]]])]);

    Livewire::test(Search::class)
        ->set('query', '35050027')
        ->call('search')
        ->assertSet('resultType', 'cvr');
});

it('🚨 REVIEW-FUND: eksempel-chips renderes OG udloeser en soegning', function () {
    // Chips laa foer paa den type-laaste skaerm, som naesten ingen naaede.
    // Nu er de forsidens primaere indgang for en foerstegangsbruger, saa
    // baade renderingen og klik-stien skal daekkes — de var udaekkede da de
    // var begravet.
    //
    // 🪤 `selectSuggestion()` forgrener paa $suggestionType === 'company' og
    // slaar chip-teksten op i $suggestions. En chip er IKKE et suggestion,
    // saa opslaget rammer ingenting; den maa falde igennem til at saette
    // query og soege. Det er den gren testen sikrer.
    Http::fake(['*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
        'name' => 'Test A/S', 'cvr' => '56811913',
    ]]])]);

    $test = Livewire::test(Search::class);

    expect($test->get('chips'))->toHaveCount(4);

    $test->call('selectSuggestion', '56811913')
        ->assertSet('query', '56811913')
        ->assertSet('resultType', 'cvr');
});
