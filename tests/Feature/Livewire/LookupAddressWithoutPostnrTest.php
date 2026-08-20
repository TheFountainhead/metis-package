<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Lookup;

/*
 * 🚨 DEN TREDJE DOER. Flare #9104992 (117 forekomster, prod).
 *
 * `Search::search():282` har haft en postnummer-guard siden 18/8. Men
 * `/lookup/address/{query}` er en ANDEN doer ind i samme produkt, og den
 * havde ingen. Entry point i Flare-occurrencen 19/8 kl. 10:48 UTC var
 * praecis den rute:
 *
 *   https://metis.frankston.io/lookup/address/S%C3%B8ndergade%2043A
 *
 * Compounden fra 18/8 forudsagde det ordret ("guarden fandtes paa 1 af 3
 * doere") — rettelsen dengang lukkede KILDEN (knappen), ikke VEJEN. Et
 * bogmaerke, et delt link eller et nyt kaldested rammer stadig tilstanden.
 *
 * Maalt mod prod 20/8:
 *   parseAddress('Agernskrænten 33')        -> zip: ''      -> 422
 *   parseAddress('Agernskrænten 33, 2750')  -> zip: '2750'  -> oploest
 */

beforeEach(function () {
    config(['metis.gating.enabled' => false]);
});

it('kalder ALDRIG property/analysis for en adresse uden postnummer', function () {
    Http::fake([
        '*/v1/map/autocomplete*' => Http::response([
            'data' => [['tekst' => 'Søndergade 43A, 4653 Karise']],
        ], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    Livewire::test(Lookup::class, ['type' => 'address', 'query' => 'Søndergade 43A'])
        ->assertSet('ufuldstaendigAdresse', true);

    // Selve tingen: det er DET kald der gav 422. Et fejlflag alene ville
    // vaere en PROXY — det her maaler at kaldet ikke sker.
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/property/analysis'));
});

it('lader en adresse MED postnummer passere', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    Livewire::test(Lookup::class, ['type' => 'address', 'query' => 'Søndergade 43A, 4653'])
        ->assertSet('ufuldstaendigAdresse', false);
});

it('roerer ikke andre opslagstyper', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    Livewire::test(Lookup::class, ['type' => 'cvr', 'query' => '22756214'])
        ->assertSet('ufuldstaendigAdresse', false);
});

/*
 * 🚨 ET FLAG ER IKKE EN VISNING. Sektionerne er `lazy`, saa de henter selv
 * deres data via en Livewire-POST — praecis som kvote-gaten allerede har
 * laert kodebasen (lookup.blade.php:16: "Et skjult `<div hidden>` var ikke
 * nok — kaldene ville stadig ske").
 *
 * Uden en gren i bladen ville guarden saette sit flag og saa lade
 * adressesektionerne rendere alligevel. Brugeren skal se HVORFOR opslaget
 * ikke kunne udfoeres, og faa forslagene at vaelge imellem.
 */
it('viser forklaring og forslag i stedet for sektionerne', function () {
    Http::fake([
        '*/v1/map/autocomplete*' => Http::response([
            'data' => [['tekst' => 'Søndergade 43A, 4653 Karise']],
        ], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    Livewire::test(Lookup::class, ['type' => 'address', 'query' => 'Søndergade 43A'])
        ->assertSee('Søndergade 43A, 4653 Karise')      // forslaget er der at vaelge
        ->assertDontSee('metis-address-bbr', false);    // sektionerne renderes IKKE
});

/*
 * 🚨 REVIEW-FUND (P1): en FEJLET autocomplete blev til opdigtede adresser.
 *
 * `rescue()` fanger kun exceptions — og `addressAutocomplete()` kaster ikke.
 * `get()` sender en RequestException gennem `errorFrom()`, som RETURNERER
 * `['error' => 'upstream_error', 'status' => 500]`. Den array havde
 * `count() > 0`, saa @foreach'en itererede dens VAERDIER og renderede:
 *
 *   <a href="/lookup/address/upstream_error">upstream_error</a>
 *   <a href="/lookup/address/500">500</a>
 *
 * Brugeren fik "vælg den rigtige nedenfor" og to klikbare loegne. Samme
 * fejlklasse som 1cdff86 lige har lukket ét lag nede.
 */
it('viser ikke en fejl-array som adresseforslag', function () {
    Http::fake([
        '*/v1/map/autocomplete*' => Http::response(['message' => 'boom'], 500),
        '*' => Http::response(['data' => []], 200),
    ]);

    Livewire::test(Lookup::class, ['type' => 'address', 'query' => 'Søndergade 43A'])
        ->assertSet('ufuldstaendigAdresse', true)
        ->assertSet('forslag', [])
        ->assertDontSee('upstream_error')
        ->assertSee('Vi fandt ingen forslag');
});

/*
 * 🚨 REVIEW-FUND (P1): guarden OMGIK kvote-gaten.
 *
 * Postnummer-guarden returnerede FOER `skalGates()`. Begrundelsen — "et
 * opslag vi ved vil fejle maa ikke koste en kvote" — er rigtig om at
 * TAELLE, men den sprang gaten helt over OG lavede stadig et udgaaende
 * kald. Maalt over kvote: 5 af 5 requests slap forbi og udloeste 5
 * autocomplete-kald; med postnummer blev den samme bruger gated.
 *
 * `/lookup/{type}/{query}` i routes/embedded.php har INGEN throttle.
 */
it('gater et opslag over kvote FOER den henter forslag', function () {
    config(['metis.gating.enabled' => true]);
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    session(['metis_lookup_count' => 99]);

    Livewire::test(Lookup::class, ['type' => 'address', 'query' => 'Søndergade 43A'])
        ->assertSet('gated', true)
        ->assertSet('ufuldstaendigAdresse', false);

    // Selve tingen: ingen udgaaende kald for en bruger over kvoten.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'autocomplete'));
});

/*
 * 🚨 REVIEW-FUND (P1): route() encoder IKKE '/'.
 *
 * Maalt: route('metis.lookup', ['query' => '../../../admin']) giver
 * /lookup/address/../../../admin — browseren normaliserer '../' vaek FOER
 * afsendelse, saa linket navigerer et helt andet sted hen i appen.
 * $f['tekst'] er upstream autocomplete-data, altsaa ikke vores input.
 * `MetisLink.php:87` rawurlencode'r allerede; her blev det glemt.
 */
it('encoder skraastreger i forslagslinks', function () {
    config(['metis.gating.enabled' => false]);
    Http::fake([
        '*/v1/map/autocomplete*' => Http::response(['data' => [
            ['tekst' => '../../../admin'],
        ]], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    $html = Livewire::test(Lookup::class, ['type' => 'address', 'query' => 'Søndergade 43A'])->html();

    expect($html)->not->toContain('/lookup/address/../../../admin');
    expect($html)->toContain('..%2F..%2F..%2Fadmin');
});

/*
 * 🚨 REVIEW-FUND (P2): et forslag med ikke-skalarr `tekst` tog siden ned.
 * Samme defekt som `postal_code` fik en is_scalar-guard for ét lag oppe.
 */
it('overlever et forslag hvor tekst ikke er en streng', function () {
    config(['metis.gating.enabled' => false]);
    Http::fake([
        '*/v1/map/autocomplete*' => Http::response(['data' => [
            ['tekst' => ['array' => 'ikke en streng']],
            ['tekst' => 'Søndergade 43A, 4653 Karise'],
        ]], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    Livewire::test(Lookup::class, ['type' => 'address', 'query' => 'Søndergade 43A'])
        ->assertSee('Søndergade 43A, 4653 Karise');
});
