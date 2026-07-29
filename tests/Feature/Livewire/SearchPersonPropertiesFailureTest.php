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
    ]));

    return Livewire::test(Search::class)
        ->set('searchMode', 'person')
        ->set('query', 'Test Person')
        ->call('search');
}

it('tells the user when the portfolio lookup finds no person, instead of failing silently', function () {
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['error' => 'Person not found'], 404),
    ])->call('loadPersonProperties');

    // 'empty' — ikke null. Uden dette kan viewet ikke skelne "ikke hentet
    // endnu" fra "hentet, ingen data", og knappen ville komme igen.
    expect($test->get('personPropertiesStatus'))->toBe('empty');
});

it('distinguishes a real fetch failure from an empty result', function () {
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::failedConnection('cURL error 28'),
    ])->call('loadPersonProperties');

    // En timeout er IKKE "ingen ejendomme". Retry giver mening her, ikke ovenfor.
    expect($test->get('personPropertiesStatus'))->toBe('failed');
});

it('marks a 200 response with no companies as empty, not loaded', function () {
    // Andet tavse hul: et gyldigt svar uden selskaber ramte samme blinde
    // Blade-gate som null gjorde.
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['data' => [
            'person_name' => 'Test Person',
            'companies' => [],
            'summary' => ['company_count' => 0, 'total_properties' => 0, 'total_valuation' => 0],
        ]]),
    ])->call('loadPersonProperties');

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

    expect($test->get('personPropertiesStatus'))->toBe('empty');

    $test->set('query', 'Anden Person')->call('search');

    expect($test->get('personPropertiesStatus'))->toBe('idle');
});

it('clears the portfolio when the user cross-references to another entity', function () {
    // Femte nulstillings-sti, fundet under implementeringen: crossReference()
    // nulstillede hverken $personProperties eller status. En bruger der klikkede
    // "Slå op" paa et selskab tog derfor den forrige persons ejendomsliste — og
    // dens besked — med over paa det nye opslag.
    $test = searchForPerson([
        '*person-property-portfolio*' => Http::response(['error' => 'Person not found'], 404),
    ])->call('loadPersonProperties');

    expect($test->get('personPropertiesStatus'))->toBe('empty');

    $test->call('crossReference', 'cvr', '12345678');

    expect($test->get('personPropertiesStatus'))->toBe('idle')
        ->and($test->get('personProperties'))->toBeNull();
});
