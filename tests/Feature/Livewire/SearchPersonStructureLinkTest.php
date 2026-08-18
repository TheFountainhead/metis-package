<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

/**
 * Ejerskabsgrafen var UOPNAAELIG fra en soegning.
 *
 * Grafen (metis-person-structure) bor KUN i lookup.blade.php. Soegesiden
 * renderede personens roller og ejendomstal, men havde ingen lenke til
 * /lookup/person/{navn} overhovedet — kun "Slaa op" pr. SELSKAB. En bruger
 * der soegte et personnavn kunne derfor ikke naa grafen uden selv at gaette
 * URL'en.
 *
 * Maalt paa prod 18/8: /?q=Frederik+larnaes gav 0 forekomster af
 * 'person-structure', 'Selskabsstruktur' og 'mgraph'. Grafen selv virkede
 * fint — hydrering mod prod gav 9 noder og 9 kanter.
 *
 * 🚨 EN knap PR. TRAEF, ikke én for hele resultatet.
 *
 * 🪤 Begrundelsen blev RETTET efter review 18/8. Foerste udgave paastod at
 * "et navneopslag rammer altid loftet paa 20 traef". Det gaelder CVR-laget
 * (searchDeltagereByName), men IKKE denne kodesti: RegistryApi::
 * searchPersonByName() slutter paa `return [[...]]` og kollapser svaret til
 * praecis én person foer bladen ser det. Knappen ligger stadig i loekken —
 * den er korrekt DER, og den nederste test beviser adfaerden ved at saette
 * $result direkte med to personer.
 */
function singlePersonApiResult(): array
{
    return ['data' => [
        'person_name' => 'Frederik Gregers Dannisgård Larnæs',
        'companies' => [[
            'cvr' => '45170209',
            'name' => 'Inova ApS',
            'status' => 'NORMAL',
            'roles' => [[
                'role_label' => 'Reelle ejere',
                'is_current' => true,
                'ownership_share' => 100.0,
            ]],
        ]],
    ]];
}

function searchPersonName(string $query = 'Frederik Larnæs'): \Livewire\Features\SupportTesting\Testable
{
    Http::fake([
        '*person-roles*' => Http::response(singlePersonApiResult()),
        '*company/*property-portfolio*' => Http::response(['data' => ['portfolio' => ['total_count' => 4]]]),
        '*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => []]]),
    ]);

    return Livewire::test(Search::class)
        ->set('query', $query)
        ->call('search');
}

it('lenker fra et persontraef til ejerskabsgrafen', function () {
    // data-testid frem for tekst: assertSee matcher HELE siden, saa en
    // assertion paa "Se selskabsstruktur" ville ogsaa passe hvis strengen
    // dukkede op i en helt anden sektion.
    searchPersonName()->assertSee('data-testid="person-structure-link"', false);
});

it('🪤 encoder navnet, saa et "?" ikke afkorter opslaget tavst', function () {
    // Rute-segmentet er `->where('query', '.*')`, saa Laravel efterlader `?`
    // og `#` RAA. Browseren laeser dem som query-/fragment-skilletegn, og
    // Lookup::mount() faar et AFKORTET navn — intet 404, bare et opslag paa
    // en anden person. Fanget af review 18/8; verificeret mod prod.
    Http::fake([
        '*person-roles*' => Http::response(['data' => [
            'person_name' => 'Navn?med=query',
            'companies' => [],
        ]]),
        '*company/*property-portfolio*' => Http::response(['data' => ['portfolio' => ['total_count' => 0]]]),
        '*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => []]]),
    ]);

    $html = Livewire::test(Search::class)->set('query', 'Navn')->call('search')->html();

    // Det raa '?' maa ALDRIG staa i href — saa ville alt efter det falde bort.
    expect($html)->toContain('person/Navn%3Fmed%3Dquery')
        ->and($html)->not->toContain('person/Navn?med=query');
});

it('peger paa lookup-ruten med det fulde CVR-navn, ikke soegestrengen', function () {
    // Soegestrengen er "Frederik Larnæs"; CVR-navnet er laengere. Lookup-siden
    // slaar op paa navnet, saa det mere specifikke navn giver et bedre match.
    // Bryder linket sammen til soegestrengen, rammer opslaget bredere end noedigt.
    $expected = route('metis.lookup', [
        'type' => 'person',
        'query' => 'Frederik Gregers Dannisgård Larnæs',
    ]);

    searchPersonName()->assertSee($expected, false);
});

it('🪤 knappen staar INDE i personloekken, ikke ved siden af resultatet', function () {
    // Beviset loekke-testen ovenfor ikke kan give: knappen skal ligge mellem
    // personens navn og personens rolle-kort. Ligger den udenfor, ville et
    // fremtidigt andet traef ikke faa sin egen — og hele begrundelsen for at
    // vaelge "liste + knap" frem for et automatisk redirect falder.
    $html = searchPersonName()->html();

    // 🪤 Ankrene er data-testid, IKKE brugervendt tekst. Foerste udgave brugte
    // strpos($html, 'Roller') — review 18/8 beviste at en harmloes ny label
    // ("Roller og ejerskab") hoejere paa siden gjorde testen ROED uden at
    // feature'en fejlede. En falsk roed laerer teamet at ignorere testen, og
    // dette er den ENESTE test der fanger at knappen forlader loekken.
    $navn = strpos($html, 'data-testid="person-name"');
    $knap = strpos($html, 'data-testid="person-structure-link"');
    $roller = strpos($html, 'data-testid="person-roles-heading"');

    expect($navn)->toBeLessThan($knap)
        ->and($knap)->toBeLessThan($roller);
});

it('🪤 giver HVER person sin egen knap naar der ER flere traef', function () {
    // 🚨 DENNE TEST ER GRUNDEN TIL AT KNAPPEN LIGGER I @foreach.
    //
    // Alle andre tests her koerer paa én person, og @foreach efterlader INGEN
    // markoer i den renderede HTML. Med én person er "inde i loekken" og "lige
    // foer loekken" bogstaveligt talt umuligt at skelne i output — review byggede
    // en mutant der beviste det ved at overleve hver eneste raekkefoelge-assertion.
    //
    // 🔑 Instrumentet skal konstruere tilstanden hvor det positive KAN ske.
    // API-laget kan ikke: RegistryApi::searchPersonByName() slutter paa
    // `return [[...]]` og giver ALTID praecis én person. Saa vi saetter
    // $result direkte og springer datalaget over — det er den eneste maade at
    // observere loekke-adfaerden paa.
    $html = Livewire::test(Search::class)
        ->set('resultType', 'name')
        ->set('result', ['persons' => [
            ['name' => 'Frederik Gregers Dannisgård Larnæs', 'roles' => [], 'owned_companies' => []],
            ['name' => 'Jesper Friis Larnæs', 'roles' => [], 'owned_companies' => []],
        ]])
        ->html();

    expect(substr_count($html, 'data-testid="person-structure-link"'))->toBe(2)
        ->and($html)->toContain(rawurlencode('Frederik Gregers Dannisgård Larnæs'))
        ->and($html)->toContain(rawurlencode('Jesper Friis Larnæs'));
});
