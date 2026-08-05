<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

/**
 * "Vis alle ejendomme"-knappen kunne fejle helt tavst.
 *
 * Knappen vises kun når total_properties > 0, så brugeren er ALLEREDE fortalt
 * at der er ejendomme. Klikker hun, og kaldet giver 404 (personen findes ikke
 * i CVR) eller en netværksfejl, satte loadPersonProperties() bare
 * $personProperties = null. Blade-blokken der viser porteføljen er gated på
 * `@if($personProperties && ...)`, så INTET blev renderet — og knappen dukkede
 * op igen, fordi dens egen gate er `empty($personProperties)`.
 *
 * Resultat: et klik uden nogen synlig effekt. Ingen fejl, ingen forklaring,
 * intet at debugge på. Værre end en forkert besked, fordi brugeren
 * konkluderer at Metis er i stykker frem for at data mangler.
 *
 * Flare 29/7 (2 forekomster) afslørede den: RequestException 404
 * "Person not found" blev fanget og kastet væk.
 */
function personNameResult(): array
{
    // Formen searchPersonByName() faktisk laeser: person_name paa data-niveau
    // plus companies[] med roller. Den udleder selv owned_companies.
    return ['data' => [
        'person_name' => 'Test Person',
        'companies' => [[
            'cvr' => '12345678',
            'name' => 'Test ApS',
            'status' => 'NORMAL',
            'roles' => [[
                'role_label' => 'Reelle ejere',
                'is_current' => true,
                'ownership_share' => 100.0,
            ]],
        ]],
    ]];
}

function searchForPerson(array $fakes): \Livewire\Features\SupportTesting\Testable
{
    Http::fake(array_merge($fakes, [
        '*person-roles*' => Http::response(personNameResult()),
        // searchPersonByName() slaar property_count op pr. selskab.
        '*company/*property-portfolio*' => Http::response(['data' => ['portfolio' => ['total_count' => 4]]]),
        // 🚨 5/8: mode-bypasset i performSearch() er fjernet, saa et NAVN nu
        // rammer BEGGE endpoints — ogsaa i person-mode. Foer spurgte vi kun
        // person-roles og fandt derfor aldrig selskaber med samme navn.
        '*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => []]]),
    ]));

    return Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', 'Test Person')
        ->call('search');
}

it('never claims the person has no properties when the name lookup failed', function () {
    // 404 = navneopslaget fandt ingen person (CvrController: searchPersonRolesByName
    // gav falsy). Det er "vi slog ikke op" — IKKE "ingen ejendomme".
    //
    // Foerste udgave af dette fix mappede 404 til 'empty' og lod viewet sige
    // "Ingen ejendomme fundet". Knappen vises kun naar vi ALLEREDE har skrevet
    // "N ejendomme via M selskaber", saa det var en falsk autoritativ
    // benaegtelse — praecis den fejlklasse fixet skulle fjerne.
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['error' => 'Person not found'], 404),
    ])->call('loadPersonProperties');

    $test->assertSee('Vi kunne ikke slå ejendommene op')
        ->assertDontSee('Ingen ejendomme fundet');

    expect($test->get('personPropertiesStatus'))->toBe('not_found');
});

it('shows the failure message and hides the button after a transport error', function () {
    // Blade-daekning: den brugervendte fejl var at knappen kom igen og INTET
    // blev renderet. Assertions paa statusfeltet alene ville ikke fange en
    // stavefejl i en @if-gate — samme blindhed som #[Locked]-incidenten.
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::failedConnection('cURL error 28'),
    ])->call('loadPersonProperties');

    $test->assertSee('Ejendomsporteføljen kunne ikke hentes.')
        ->assertSee('Prøv igen')
        ->assertDontSee('Vis alle ejendomme');
});

it('stops offering retry after three failures', function () {
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::failedConnection('cURL error 28'),
    ])->call('loadPersonProperties')
        ->call('loadPersonProperties')
        ->call('loadPersonProperties');

    expect($test->get('personPropertiesStatus'))->toBe('permanent');

    $test->assertSee('Vi kan ikke hente data lige nu.')
        ->assertDontSee('Prøv igen');
});

it('distinguishes a real fetch failure from an empty result', function () {
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::failedConnection('cURL error 28'),
    ])->call('loadPersonProperties');

    // En timeout er IKKE "ingen ejendomme". Retry giver mening her, ikke ovenfor.
    expect($test->get('personPropertiesStatus'))->toBe('failed');
});

it('does not contradict the header when a 200 lists no companies', function () {
    // Andet tavse hul: et gyldigt svar uden selskaber ramte samme blinde
    // Blade-gate som null gjorde. Men her har overskriften ALLEREDE sagt at
    // der er ejendomme (total_properties > 0 er knappens egen gate), saa
    // "Ingen ejendomme fundet" ville modsige skaermen. De to tal kommer fra
    // hver sin kilde og kan divergere legitimt.
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['data' => [
            'person_name' => 'Test Person',
            'companies' => [],
            'summary' => ['company_count' => 0, 'total_properties' => 0, 'total_valuation' => 0],
        ]]),
    ])->call('loadPersonProperties');

    $test->assertSee('Ejendommene kunne ikke listes samlet.')
        ->assertDontSee('Ingen ejendomme fundet');

    expect($test->get('personPropertiesStatus'))->toBe('empty');
});

it('loads the portfolio on the happy path', function () {
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['data' => [
            'person_name' => 'Test Person',
            'companies' => [[
                'cvr' => '12345678',
                'name' => 'Test ApS',
                'portfolio' => ['properties' => [['bfe' => '1234', 'address' => 'Testvej 1']]],
            ]],
            'summary' => ['company_count' => 1, 'total_properties' => 1, 'total_valuation' => 5_000_000],
        ]]),
    ])->call('loadPersonProperties');

    expect($test->get('personPropertiesStatus'))->toBe('loaded')
        ->and($test->get('personProperties')['summary']['total_properties'])->toBe(1);
});

it('clears the status when a new search starts, so a stale message cannot survive', function () {
    // Invarianten skal holde hvert sted state nulstilles — samme lektie som
    // PersonStructure's fire guards (#128).
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['error' => 'Person not found'], 404),
    ])->call('loadPersonProperties');

    expect($test->get('personPropertiesStatus'))->toBe('not_found');

    // Kald search() UDEN forudgaaende set('query'): stub-tricket viste at
    // ->set() udloeser updatedQuery(), som rydder feltet foerst — saa testen
    // forblev groen selv naar search()s egen reset blev fjernet. Den beviste
    // en anden mekanisme end den hed efter.
    $test->call('search');

    expect($test->get('personPropertiesStatus'))->toBe('pending');
});

it('clears the portfolio when updatedQuery runs, as a separate path', function () {
    // Den sti test ovenfor ved et uheld maalte. Nu daekket eksplicit.
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['error' => 'Person not found'], 404),
    ])->call('loadPersonProperties');

    $test->set('query', 'ab');   // < 3 tegn: updatedQuery() returnerer efter reset

    expect($test->get('personPropertiesStatus'))->toBe('pending');
});

it('clears the portfolio when the user cross-references to another entity', function () {
    // Femte nulstillings-sti, fundet under implementeringen: crossReference()
    // nulstillede hverken $personProperties eller status. En bruger der klikkede
    // "Slå op" paa et selskab tog derfor den forrige persons ejendomsliste — og
    // dens besked — med over paa det nye opslag.
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['error' => 'Person not found'], 404),
    ])->call('loadPersonProperties');

    expect($test->get('personPropertiesStatus'))->toBe('not_found');

    $test->call('crossReference', 'cvr', '12345678');

    expect($test->get('personPropertiesStatus'))->toBe('pending')
        ->and($test->get('personProperties'))->toBeNull();
});

it('clears the portfolio when the user switches search type', function () {
    // Sjette nulstillings-sti. setSearchMode() rydder query og result, men
    // lod feltparret staa: skiftede brugeren fra Person til Selskab efter et
    // portefoelje-opslag, blev den forrige persons ejendomsliste og dens
    // besked staaende paa en ellers tom skaerm.
    //
    // Seks stier, alle seks fundet ved tilfaelde eller fejlrapport frem for
    // systematisk laesning — se klassekommentaren og #128.
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['error' => 'Person not found'], 404),
    ])->call('loadPersonProperties');

    expect($test->get('personPropertiesStatus'))->toBe('not_found');

    $test->call('setSearchMode', 'company');

    expect($test->get('personPropertiesStatus'))->toBe('pending')
        ->and($test->get('personProperties'))->toBeNull();
});
