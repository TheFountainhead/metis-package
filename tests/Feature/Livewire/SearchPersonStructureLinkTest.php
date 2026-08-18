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
 * 🚨 EN knap PR. TRAEF, ikke én for hele resultatet. Et navneopslag rammer i
 * praksis altid loftet paa 20 traef (Elasticsearch matcher loest paa
 * efternavnet), saa en enkelt knap ville vaelge person paa brugerens vegne.
 * Det er ogsaa grunden til at et automatisk redirect blev fravalgt.
 */
function twoPersonResult(): array
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
        '*person-roles*' => Http::response(twoPersonResult()),
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

it('giver HVERT traef sin egen knap', function () {
    // 🪤 DENNE TEST VAR VACUOUS I FOERSTE UDGAVE. Den sammenlignede antal
    // knapper med antal navne-overskrifter paa en fixtur med ÉN person, saa
    // 1 === 1 bestod ogsaa naar knappen blev flyttet UD af @foreach-loekken
    // (mutations-tjekket 18/8: mutanten overlevede).
    //
    // 🔑 Og aarsagen viste sig at vaere vaerd at kende: RegistryApi::
    // searchPersonByName() slutter paa `return [[...]]` — den returnerer
    // ALTID praecis én person, bygget af svarets `person_name`. Loekken kan
    // med dagens datalag aldrig koere mere end én gang.
    //
    // Knappen bliver derfor i loekken (den er korrekt DER, og det er
    // gratis), men testen maaler nu det den faktisk kan maale: at knappen
    // hoerer til det RENDEREDE traef og baerer dets navn. Skulle datalaget
    // en dag levere flere personer, faar hver sin knap uden aendringer her.
    $html = searchPersonName()->html();

    expect(substr_count($html, 'data-testid="person-structure-link"'))->toBe(1)
        ->and($html)->toContain('Frederik Gregers Dannisgård Larnæs');
});

it('🪤 knappen staar INDE i personloekken, ikke ved siden af resultatet', function () {
    // Beviset loekke-testen ovenfor ikke kan give: knappen skal ligge mellem
    // personens navn og personens rolle-kort. Ligger den udenfor, ville et
    // fremtidigt andet traef ikke faa sin egen — og hele begrundelsen for at
    // vaelge "liste + knap" frem for et automatisk redirect falder.
    $html = searchPersonName()->html();

    $navn = strpos($html, 'Frederik Gregers Dannisgård Larnæs');
    $knap = strpos($html, 'data-testid="person-structure-link"');
    $roller = strpos($html, 'Roller');

    expect($navn)->toBeLessThan($knap)
        ->and($knap)->toBeLessThan($roller);
});
