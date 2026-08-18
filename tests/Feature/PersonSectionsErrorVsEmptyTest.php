<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Person-sektionerne havde samme fejlklasse som adresse-sektionerne.
 *
 * 🚨 MEN VAERRE, FORDI DE VISER TAL. Maalt foer rettelsen paa et misdannet
 * 200-svar:
 *
 *   PersonSummary   "Overblik · 0 Aktive selskaber · 0 Ejendomme"
 *   PersonRoles     "No company roles found for this person."
 *   PersonInfo      "Properties owned: 0"
 *   PersonRelations "Ingen relaterede personer fundet."
 *
 * Et tal er den mest overbevisende falske benaegtelse: "0 Aktive selskaber"
 * ligner et resultat, ikke et mislykket opslag. En bruger der laeser det,
 * konkluderer at personen ikke har selskaber.
 *
 * 🪤 JEG DIAGNOSTICEREDE DET FORKERT FOERST. Jeg troede `rescue()` gav null
 * naar kaldet kastede, og at guarden derfor var virkningsloes. Maalt: den
 * goer den ikke — `post()` fanger ConnectionException, RuntimeException og
 * 5xx selv og returnerer et fejl-array. Den rigtige aarsag var enklere:
 * sektionerne HAVDE slet ingen guard.
 *
 * Praeeksisterende fejl, verificeret mod origin/main af review — ikke en
 * regression fra #176.
 */
beforeEach(fn () => Cache::flush());

dataset('person-sektioner', [
    'overblik' => [\TheFountainhead\Metis\Livewire\Sections\PersonSummary::class],
    'roller' => [\TheFountainhead\Metis\Livewire\Sections\PersonRoles::class],
    'information' => [\TheFountainhead\Metis\Livewire\Sections\PersonInfo::class],
    'relationer' => [\TheFountainhead\Metis\Livewire\Sections\PersonRelations::class],
    'ejendomme' => [\TheFountainhead\Metis\Livewire\Sections\PersonProperties::class],
]);

it('🚨 siger ALDRIG "0" eller "ingen" naar opslaget fejlede', function (string $sektion) {
    // Et 200-svar uden 'data'-noegle: post() giver
    // ['error' => 'malformed_response'], og rescue() lader det passere.
    Http::fake(['*' => Http::response(['uventet' => 'form'], 200)]);

    $test = Livewire::test($sektion, ['query' => '0101011234']);

    expect($test->get('hasError'))->toBeTrue()
        ->and($test->html())->toContain('opslaget lykkedes ikke');
})->with('person-sektioner');

it('🪤 fanger ogsaa en TRANSPORTFEJL, ikke kun et misdannet svar', function (string $sektion) {
    // 🪤 DENNE TEST HED FOERST "naar rescue() slugte en exception (null)" og
    // var VACUOUS. Jeg antog at rescue() gav null ved en kastet exception —
    // det goer den ikke. Maalt med en probe: ConnectionException,
    // RuntimeException OG HTTP 500 giver alle
    // ['error' => 'upstream_error', 'status' => 0], fordi post() fanger dem
    // selv foer rescue() ser noget. En mutation der fjernede null-grenen i
    // opslagFejlede() overlevede hele filen.
    //
    // Testen maaler nu det den faktisk rammer: at en TRANSPORTFEJL (den
    // hyppigste prod-fejl) ogsaa flager — ikke kun et misdannet 200-svar.
    Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);

    $test = Livewire::test($sektion, ['query' => '0101011234']);

    expect($test->get('hasError'))->toBeTrue()
        ->and($test->get('errorMessage'))->toBe('lookup_failed');
})->with('person-sektioner');

it('🚨 PersonSummary flager naar bare ÉN af to halvdele fejler', function () {
    // Sektionen laver to uafhaengige kald. Fejler kun det ene, ville den
    // andens tal se komplet ud ved siden af et nul der bare betyder "vi kunne
    // ikke spoerge". Derfor `||`, ikke `&&` — at kraeve at BEGGE fejler ville
    // skjule en halv fejl bag et helt tal.
    Http::fake([
        // 🪤 BEGGE endpoints slutter paa 'search-by-cpr' — et '*search-by-cpr*'
        // moenster ville fange dem begge. Brug de fulde stier.
        '*/v1/cvr/search-by-cpr' => Http::response(['data' => ['companies' => [
            ['cvr' => '12345678', 'name' => 'Rigtig ApS', 'status' => 'NORMAL'],
        ]]], 200),
        // Ejendomskaldet fejler.
        '*/v1/property-tinglysning/search-by-cpr' => Http::response(['uventet' => 'form'], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    $test = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\PersonSummary::class,
        ['query' => '0101011234']);

    expect($test->get('hasError'))->toBeTrue()
        // Og tallet fra den lykkedes halvdel maa ikke staa alene som facit.
        ->and($test->html())->toContain('opslaget lykkedes ikke');
});

it('viser stadig den AEGTE tomme tilstand naar kaldet lykkes', function () {
    // Guarden maa ikke goere sandheden utilgaengelig: en person UDEN roller
    // skal stadig kunne faa det at vide.
    Http::fake(['*' => Http::response(['data' => ['companies' => []]], 200)]);

    $html = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\PersonRoles::class,
        ['query' => '0101011234'])->html();

    expect($html)->toContain('No company roles found')
        ->and($html)->not->toContain('opslaget lykkedes ikke');
});
