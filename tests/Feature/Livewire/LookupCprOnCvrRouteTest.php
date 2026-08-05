<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Lookup;
use TheFountainhead\Metis\Models\MetisLookup;

uses(RefreshDatabase::class);

/**
 * Et CPR paa /lookup/cvr/ skal sendes til CPR-siden, ikke give en fejlside.
 *
 * 🚨 MAALT 5/8 (Flare #9104992): fejlen gik fra n=2 til n=14 paa seks doegn i
 * PROD-trafik. `lookup.blade.php:24` loader OTTE selskabssektioner for
 * type=cvr; alle otte kaldte API'et med det ugyldige CVR, alle fik 422, og
 * ingen validerede foerst. Én fejlindtastning blev til en byge af exceptions.
 *
 * Brugeren saa en fejlside — selvom vi HAR en CPR-side.
 */
it('sender et CPR paa /lookup/cvr/ videre til CPR-siden', function () {
    Livewire::test(Lookup::class, ['type' => 'cvr', 'query' => '1234567890'])
        ->assertRedirect(route('metis.lookup', ['type' => 'cpr', 'query' => '1234567890']));
});

it('🚨 gemmer IKKE CPR-et i historikken under forkert type', function () {
    // 🪤 `metis_lookups.search_term` er vores EGEN tabel — Flare censurerer
    // CPR i sit UI, men det gjorde vores historik ikke. Maalt: 4 raekker laa
    // med et CPR under search_type='cvr'.
    //
    // Derfor redirectes FOER MetisLookup::create(). Testen daekker praecis den
    // raekkefoelge: uden den ville raekken vaere skrevet inden redirect.
    Livewire::test(Lookup::class, ['type' => 'cvr', 'query' => '1234567890']);

    expect(MetisLookup::where('search_term', '1234567890')->exists())->toBeFalse();
});

it('roerer ikke et gyldigt CVR', function () {
    Livewire::test(Lookup::class, ['type' => 'cvr', 'query' => '35050027'])
        ->assertNoRedirect()
        ->assertSet('type', 'cvr')
        ->assertSet('query', '35050027');

    // Og det gyldige opslag SKAL i historikken — ellers ville rettelsen have
    // slaaet logningen fra for alle.
    expect(MetisLookup::where('search_term', '35050027')->exists())->toBeTrue();
});

it('roerer ikke CPR-siden selv', function () {
    // Samme 10-cifrede vaerdi paa den RIGTIGE rute maa ikke redirecte —
    // ellers ville den loope.
    Livewire::test(Lookup::class, ['type' => 'cpr', 'query' => '1234567890'])
        ->assertNoRedirect()
        ->assertSet('type', 'cpr');
});

it('🚨 REVIEW-FUND: fanger ogsaa CPR MED bindestreg og mellemrum', function () {
    // 🚨 Foerste udkast brugte sin egen regex `^\d{10}$` og missede dermed
    // den form danskere faktisk skriver: DDMMYY-XXXX.
    //
    // Maalt gennem en RIGTIG HTTP-request (ikke kun Livewire::test):
    //   /lookup/cvr/1234567890   -> 302, ingen historik   ✅
    //   /lookup/cvr/123456-7890  -> 500, CPR GEMT         🚨
    //
    // Kodebasen havde ALLEREDE tre detektorer der accepterer bindestreg
    // (SearchDetector:26, Search:127, Search:263). Nu delegeres der til dem.
    foreach (['123456-7890', ' 1234567890 '] as $q) {
        Livewire::test(Lookup::class, ['type' => 'cvr', 'query' => $q])
            ->assertRedirect(route('metis.lookup', ['type' => 'cpr', 'query' => $q]));

        expect(MetisLookup::where('search_term', $q)->exists())->toBeFalse();
    }
});

it('roerer ikke andre laengder', function () {
    // 8 cifre = CVR, 9 og 11 er hverken-eller. Kun praecis 10 er CPR-formen.
    foreach (['123456789', '12345678901'] as $q) {
        Livewire::test(Lookup::class, ['type' => 'cvr', 'query' => $q])
            ->assertNoRedirect();
    }
});

it('🚨 verificeret gennem en RIGTIG HTTP-request, ikke kun Livewire::test', function () {
    // 🪤 Livewire::test() koerer hverken morph eller den fulde request-cyklus.
    // Reviewet fandt at /lookup/cvr/123456-7890 gav 500 i en RIGTIG request,
    // mens komponent-testen var groen — fordi $type/$query var uinitialiserede
    // typed properties naar guarden returnerede foer tildelingen.
    //
    // Denne test rammer ruten som en browser goer.
    $this->get('/lookup/cvr/1234567890')
        ->assertRedirect('/lookup/cpr/1234567890');

    $this->get('/lookup/cvr/123456-7890')
        ->assertRedirect('/lookup/cpr/123456-7890');

    // Ingen af dem maa efterlade CPR i historikken.
    expect(MetisLookup::whereIn('search_term', ['1234567890', '123456-7890'])->exists())->toBeFalse();

    // Og et gyldigt CVR maa IKKE redirecte. Vi asserter paa netop det —
    // ikke paa at siden renderer. Layout-opsaetningen er et vaerts-app-forhold
    // (pakken har intet components.layouts.app), og en assertOk() ville derfor
    // teste testmiljoeet frem for komponenten.
    expect($this->get('/lookup/cvr/35050027')->isRedirect())->toBeFalse();
});
