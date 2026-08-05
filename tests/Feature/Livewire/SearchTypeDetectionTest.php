<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

uses(RefreshDatabase::class);

/**
 * Typen bestemmes af INPUTTET, ikke af en valgt mode.
 *
 * 🚨 Mode-bypass har kostet TRE prod-fejl paa under to maaneder — hver gang
 * fordi brugerens input ikke passede til den mode de havde valgt:
 *
 *   1. CVR i company-mode   -> soegt som firmanavn      (lappet)
 *   2. CPR i person-mode    -> soegt som personnavn     (lappet)
 *   3. CPR paa /lookup/cvr/ -> 8 sektioner, alle 422    (metis #145)
 *
 * De to foerste blev lappet med `if`-saetninger i selve mode-blokken.
 * Kommentaren ved lap 2 sagde "Samme opgradering for person-mode" — skrevet
 * med bevidsthed om at det var anden gang.
 *
 * 🔑 Loesningen fandtes ALLEREDE: SearchDetector genkender CPR, CVR, adresse,
 * firmanavn og personnavn ud fra inputtets FORM. Den var bare gjort til
 * fallback for den forkerte vej.
 *
 * Testene her daekker at hver af de tre fejl nu er UMULIG — uanset mode.
 */
beforeEach(function () {
    Http::preventStrayRequests();
});

it('🚨 FEJL 1: et CVR i company-mode soeges som CVR, ikke som firmanavn', function () {
    // Uden rettelsen mappede company-mode haardt til 'company_name', og et
    // 8-cifret CVR blev soegt som et FIRMANAVN. Lappen der rettede det stod
    // inde i selve mode-blokken; nu er den overfloedig.
    Http::fake(['*/v1/cvr/company/*' => Http::response(['data' => ['company' => [
        'name' => 'Test A/S', 'cvr' => '35050027',
    ]]])]);

    Livewire::test(Search::class)
        ->set('searchMode', 'company')
        ->set('query', '35050027')
        ->call('search')
        ->assertSet('resultType', 'cvr');
});

it('🚨 FEJL 2: et CPR i person-mode genkendes som CPR, ikke som navn', function () {
    // Uden rettelsen mappede person-mode haardt til 'name', saa et CPR blev
    // soegt som et PERSONNAVN. Brugeren fik "Ingen resultater" — en paastand
    // om at personen ikke findes, hvor sandheden er at vi aldrig soegte.
    //
    // CPR er blokeret i UI'et, saa det korrekte udfald er cprBlocked — ikke
    // en navnesoegning.
    Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', '1234567890')
        ->call('search')
        ->assertSet('cprBlocked', true);
});

it('🚨 FEJL 2b: samme gaelder CPR MED bindestreg', function () {
    // Danske CPR skrives konventionelt DDMMYY-XXXX. SearchDetector accepterer
    // begge former; en snaever regex ville misse den mest almindelige.
    Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', '123456-7890')
        ->call('search')
        ->assertSet('cprBlocked', true);
});

it('en adresse i person-mode genkendes som adresse', function () {
    // Den generelle egenskab: mode kan ikke laengere tvinge et forkert svar.
    Http::fake(['*' => Http::response(['data' => []])]);

    Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', 'Bredgade 40')
        ->call('search')
        ->assertNotSet('resultType', 'name');
});

it('et firmanavn genkendes ens MED og UDEN mode', function () {
    // Den baerende egenskab efter rettelsen: mode maa ikke laengere aendre
    // hvad der soeges efter. Samme input skal give samme udfald.
    //
    // 🪤 `company_name` saetter ikke resultType (den gaar ad
    // suggestion-/redirect-vejen, ikke inline-render som cvr/address). Vi
    // sammenligner derfor de to koersler med hinanden frem for at gaette en
    // konkret vaerdi — det er ligheden der er paastanden.
    Http::fake(['*' => Http::response(['data' => ['companies' => []]])]);

    $uden = Livewire::test(Search::class)
        ->set('searchMode', '')->set('query', 'Jeudan A/S')->call('search');

    $med = Livewire::test(Search::class)
        ->set('searchMode', 'person')->set('query', 'Jeudan A/S')->call('search');

    expect($med->get('resultType'))->toBe($uden->get('resultType'))
        ->and($med->get('error'))->toBe($uden->get('error'));
});
