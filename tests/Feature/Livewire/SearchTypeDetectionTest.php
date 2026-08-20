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
 * 🎯 6/8: mode-konceptet er FJERNET helt — ét soegefelt, ingen type-valg
 * (Resights-modellen). Testene her daekker derfor ikke laengere "uanset
 * mode", men at detektoren traeffer det rigtige valg paa inputtet alene.
 * De titler der naevner "company-mode"/"person-mode" er bevaret, fordi de
 * navngiver de PROD-FEJL testene stammer fra.
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
        ->set('query', '1234567890')
        ->call('search')
        ->assertSet('cprBlocked', true);
});

it('🚨 FEJL 2b: samme gaelder CPR MED bindestreg', function () {
    // Danske CPR skrives konventionelt DDMMYY-XXXX. SearchDetector accepterer
    // begge former; en snaever regex ville misse den mest almindelige.
    Livewire::test(Search::class)
        ->set('query', '123456-7890')
        ->call('search')
        ->assertSet('cprBlocked', true);
});

it('en adresse i person-mode genkendes som adresse', function () {
    // Den generelle egenskab: mode kan ikke laengere tvinge et forkert svar.
    Http::fake(['*' => Http::response(['data' => []])]);

    Livewire::test(Search::class)
        ->set('query', 'Bredgade 40')
        ->call('search')
        ->assertNotSet('resultType', 'name');
});

it('🚨 en person-soegning spoerger BAADE navn og roller', function () {
    // 🚨 Denne test sammenlignede tidligere to modes med hinanden:
    //   $ramt('company') === $ramt('')
    // Da mode-konceptet blev fjernet 6/8 blev den sammenligningen af noget
    // med sig selv — en tautologi der ikke kan fejle. Den asserter nu paa
    // det den hele tiden beskyttede: at BEGGE endpoints faktisk rammes.
    //
    // Maalt med optagne udgaaende kald FOER mode-bypasset blev fjernet:
    //   'Lars Larsen' i company-mode -> KUN search-by-name
    //   'Lars Larsen' uden mode      -> search-by-name + person-roles
    //
    // En person soegt i company-mode spurgte altsaa aldrig person-roles.
    // Brugeren fik at vide at personen ingen roller har — hvor sandheden er
    // at vi aldrig spurgte. Det er en falsk autoritativ benaegtelse.
    $urls = [];
    Http::fake(function ($request) use (&$urls) {
        $urls[] = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(['data' => []]);
    });

    Livewire::test(Search::class)
        ->set('query', 'Lars Larsen')
        ->call('search');

    $samlet = implode(' ', $urls);

    expect($samlet)->toContain('search-by-name')
        ->and($samlet)->toContain('person-roles');
});

it('🚨 autocomplete paa en adresse giver forslag', function () {
    // 🚨 Den TREDJE bypass. updatedQuery() forgrenede paa searchMode og
    // returnerede tidligt. Docblocken paastod at mode "blot giver faerre
    // forslag" — men person-grenen returnerede med suggestions = [] UANSET
    // input. En adresse i person-mode gav NUL forslag, ikke faerre.
    //
    // Samme omskrivning som ovenfor: en mode-mod-mode-sammenligning kan ikke
    // fejle naar der kun findes én vej. Vi asserter paa antallet direkte.
    // 🪤 FIXTUREN BESKREV EN FORM API'ET ALDRIG SENDER. Den var pakket ind i
    // en `adresser`-noegle; maalt mod prod 20/8 returnerer
    // /v1/map/autocomplete en FLAD liste af {tekst, data}. Nøglen `adresser`
    // findes intet andet sted i kodebasen end her. Testen var groen mod en
    // struktur der ikke eksisterer — den fejlede foerst da
    // addressAutocomplete() begyndte at validere formen.
    Http::fake(['*' => Http::response(['data' => [
        ['tekst' => 'Bredgade 40, 1260 København'],
    ]])]);

    $forslag = Livewire::test(Search::class)
        ->set('query', 'Bredgade 40')
        ->get('suggestions');

    expect($forslag)->not->toBeEmpty();
});

it('🚨 REVIEW-FUND: ?q= med CPR og MELLEMRUM blokeres ogsaa', function () {
    // 🚨 Search:127 bar en FEMTE kopi af CPR-regexen, og den normaliserede
    // ikke mellemrum — saa `?q=123456 7890` slap forbi og blev sat som query.
    // Alle fem kopier er nu samlet i SearchDetector::isCpr().
    foreach (['1234567890', '123456-7890', '123456 7890'] as $q) {
        Livewire::withQueryParams(['q' => $q])
            ->test(Search::class)
            ->assertRedirect('/');
    }

    // Og et lovligt CVR maa stadig saettes.
    Livewire::withQueryParams(['q' => '35050027'])
        ->test(Search::class)
        ->assertNoRedirect()
        ->assertSet('query', '35050027');
});
