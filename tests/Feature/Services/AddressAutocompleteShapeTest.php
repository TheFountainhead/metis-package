<?php

use Illuminate\Support\Facades\Http;
use TheFountainhead\Metis\Services\RegistryApi;

/*
 * 🚨 EN FORSLAGSLISTE ER ALDRIG EN FEJL-ARRAY.
 *
 * `get()` sender en RequestException gennem `errorFrom()`, som RETURNERER
 * ['error' => 'upstream_error', 'status' => 500]. Den array har count() == 2,
 * saa enhver `@if(count($forslag) > 0) @foreach` itererer dens VAERDIER.
 *
 * Maalt 20/8 — fire forbrugere, ingen af dem filtrerede:
 *   Index.php:38, Index.php:91      -> index.blade.php:25 bruger
 *                                      {{ $suggestion['tekst'] }} UDEN ?? =>
 *                                      TypeError, FORSIDEN gaar ned
 *   Search.php:161, Search.php:283  -> to tomme knapper under "Mente du:"
 *
 * PR #179 rettede det i `Lookup` alene. 🔑 Filteret hoerer hjemme HER, i den
 * metode alle fire kalder — ellers er det femte kaldested lige om hjoernet.
 * Samme argument som chokepunkt-guarden i resolveAddressAnalysis().
 */

it('returnerer en TOM liste naar opslaget fejler — aldrig fejl-arrayen', function () {
    Http::fake(['*/v1/map/autocomplete*' => Http::response(['message' => 'boom'], 500)]);

    expect(app(RegistryApi::class)->addressAutocomplete('Søndergade 43', 5))->toBe([]);
});

it('frasorterer raekker uden brugbar tekst', function () {
    Http::fake(['*/v1/map/autocomplete*' => Http::response(['data' => [
        ['tekst' => 'Søndergade 43A, 4653 Karise'],
        ['ingen_tekst' => 'x'],
        ['tekst' => ''],
        ['tekst' => ['array']],
        'en bar streng',
    ]], 200)]);

    expect(app(RegistryApi::class)->addressAutocomplete('Søndergade 43', 5))
        ->toBe([['tekst' => 'Søndergade 43A, 4653 Karise']]);
});

it('lader et gyldigt svar passere uroert', function () {
    Http::fake(['*/v1/map/autocomplete*' => Http::response(['data' => [
        ['tekst' => 'Søndergade 43A, 3770 Allinge'],
        ['tekst' => 'Søndergade 43A, 4653 Karise'],
    ]], 200)]);

    expect(app(RegistryApi::class)->addressAutocomplete('Søndergade 43', 5))->toHaveCount(2);
});
