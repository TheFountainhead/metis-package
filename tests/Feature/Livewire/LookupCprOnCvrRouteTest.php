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

it('roerer ikke andre laengder', function () {
    // 8 cifre = CVR, 9 og 11 er hverken-eller. Kun praecis 10 er CPR-formen.
    foreach (['123456789', '12345678901'] as $q) {
        Livewire::test(Lookup::class, ['type' => 'cvr', 'query' => $q])
            ->assertNoRedirect();
    }
});
