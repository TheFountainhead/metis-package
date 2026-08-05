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

it('🚨 REVIEW-FUND: mode aendrer ikke HVILKE endpoints der rammes', function () {
    // 🚨 Den foerste udgave af denne test sammenlignede kun resultType og
    // error — begge null/false i begge koersler. Den bestod derfor mens en
    // ANDEN mode-bypass i performSearch() stadig var live og kastede det
    // beregnede $type vaek.
    //
    // Maalt med optagne udgaaende kald FOER rettelsen:
    //   'Lars Larsen' i company-mode -> KUN search-by-name
    //   'Lars Larsen' uden mode      -> search-by-name + person-roles
    //
    // En person soegt i company-mode spurgte altsaa aldrig person-roles.
    // Brugeren fik at vide at personen ingen roller har — hvor sandheden er
    // at vi aldrig spurgte.
    //
    // Testen asserter derfor paa ENDPOINTS, ikke paa to null-skalarer.
    $ramt = function (string $mode): array {
        $urls = [];
        Http::fake(function ($request) use (&$urls) {
            $urls[] = parse_url($request->url(), PHP_URL_PATH);

            return Http::response(['data' => []]);
        });

        Livewire::test(Search::class)
            ->set('searchMode', $mode)
            ->set('query', 'Lars Larsen')
            ->call('search');

        sort($urls);

        return array_unique($urls);
    };

    expect($ramt('company'))->toBe($ramt(''))
        ->and($ramt('person'))->toBe($ramt(''));
});
