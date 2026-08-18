<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

/**
 * "Slå op" på en ejendom i personens portefølje sendte adressen ALENE.
 *
 * En adresse uden postnummer kan ikke opløses til en unik matrikel:
 * registry-api svarer 422, og alle 12 sektioner står tomme med hver sin
 * "Ingen data fundet". Det læses som en påstand om ejendommen
 * ("ingen pantebreve"), men betyder "vi ved ikke hvilken ejendom du mener".
 *
 * Målt på prod 18/8:
 *   'Søndergade 43A'              → 422 upstream_error
 *   'Søndergade 43A, 4653 Karise' → opløst
 * Autocomplete viser hvorfor: adressen findes mindst 5 steder (Allinge 3770,
 * Hornsyld 8783, Kjellerup 8620 ×3). Den rigtige var 4653 Karise, og
 * postnummeret lå i payloaden hele tiden.
 *
 * 🪤 Feltnavnet: `postal_code` i person-property-portfolio, men `zip` i
 * CPR-stiens `personal_properties` (registry-api
 * PersonPropertyPortfolioController:88). Begge er korrekte HVER FOR SIG —
 * jeg troede først person-properties.blade var buggy, men den læser sin
 * EGEN kildes navn rigtigt. Læs payloadens nøgler, antag aldrig.
 *
 * 🚨 Testen asserter på wire:click-attributtens INDHOLD, ikke på at siden
 * nævner postnummeret et sted. Adressekortet ovenfor viser også adressen,
 * så en assertSee('4653') ville være grøn uden knappen overhovedet
 * ændrede sig. Muter koden (fjern postal_code/city) → skal blive RØD.
 */
function personWithProperty(array $property): \Livewire\Features\SupportTesting\Testable
{
    Http::fake([
        '*person-roles*' => Http::response(['data' => [
            'person_name' => 'Test Person',
            'companies' => [[
                'cvr' => '12345678',
                'name' => 'Test ApS',
                'status' => 'NORMAL',
                'roles' => [['role_label' => 'Reelle ejere', 'is_current' => true, 'ownership_share' => 100.0]],
            ]],
        ]]),
        '*company/*property-portfolio*' => Http::response(['data' => ['portfolio' => ['total_count' => 4]]]),
        '*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => []]]),
        '*person-property-portfolio*' => Http::response(['data' => [
            'person_name' => 'Test Person',
            'companies' => [[
                'cvr' => '12345678',
                'name' => 'Test ApS',
                'portfolio' => ['properties' => [$property]],
            ]],
            'summary' => ['company_count' => 1, 'total_properties' => 1, 'total_valuation' => 5_000_000],
        ]]),
    ]);

    return Livewire::test(Search::class)
        ->set('query', 'Test Person')
        ->call('search')
        ->call('loadPersonProperties');
}

it('sends address, postnr and city to crossReference so the matrikel is unique', function () {
    // Søndergade 43A findes mindst 5 steder (Allinge, Hornsyld, Kjellerup x3).
    // Uden 4653 rammer opslaget den forkerte — eller ingen.
    $html = personWithProperty([
        'bfe' => '1234',
        'address' => 'Søndergade 43A',
        'postal_code' => '4653',
        'city' => 'Karise',
    ])->html();

    // @js() JSON-encoder, så æøå bliver \uXXXX. Afkod attributten i stedet for
    // at matche mod rå UTF-8 — ellers er testen grøn af den forkerte grund.
    expect(crossReferenceAddressArgs($html))->toBe(['Søndergade 43A, 4653 Karise']);
});

it('does not leave a dangling comma when postnr and city are missing', function () {
    // Payload'en er ikke garanteret at have postal_code. En adresse med
    // efterhængt ", " er værre end ingen ændring: den kan sende 422.
    $html = personWithProperty([
        'bfe' => '1234',
        'address' => 'Testvej 1',
    ])->html();

    expect(crossReferenceAddressArgs($html))->toBe(['Testvej 1']);
});

/** Træk hvert crossReference('address', ...)-argument ud, JSON-afkodet. */
function crossReferenceAddressArgs(string $html): array
{
    // Blade escaper wire:click-attributten, så &#039; er apostroffen.
    $decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

    preg_match_all("/crossReference\('address', '((?:[^'\\\\]|\\\\.)*)'\)/", $decoded, $m);

    return array_map(
        fn (string $raw) => json_decode('"'.$raw.'"'),
        $m[1],
    );
}
