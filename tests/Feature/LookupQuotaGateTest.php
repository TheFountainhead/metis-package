<?php

use Illuminate\Support\Facades\Http;

/**
 * Kvote-gaten skal gaelde BEGGE veje ind i produktet.
 *
 * 🚨 MAALT PAA PROD 9/8: gaten fandtes kun i `Search::performSearch()`. Et
 * direkte kald til `/lookup/cvr/12345678` omgik den fuldstaendigt. Verificeret
 * udefra med fem opslag i samme session: fem gange HTTP 200, syv datasektioner
 * der loadede hver gang.
 *
 * Prod samme dag: 8.259 raekker i `metis_lookups`, **0 brugere**, 0 raekker i
 * kvote-taelleren `lookups` (migreret 15/3, aldrig brugt).
 *
 * 🔑 Samme fejlklasse som noindex-hullet samme dag: beskyttelsen var bygget og
 * virkede — den sad bare kun paa den ene af to doere.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    config()->set('metis.gating.free_lookups', 1);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []])]);
});

it('lader det FOERSTE opslag passere', function () {
    $this->get('/lookup/cvr/37792594')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');
});

it('🚨 gater det ANDET opslag paa den direkte rute', function () {
    // 🪤 Kernen i fundet. Foer denne rettelse gav begge kald fuldt indhold.
    $this->withSession(['metis_lookup_count' => 1]);

    $this->get('/lookup/cvr/37792594')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

it('🚨 gatede opslag renderer INGEN datasektioner', function () {
    // 🔑 Det er ikke nok at vise gate-teksten. Sektionerne er `lazy` — bliver
    // de renderet, henter hver af dem selv sine data via en Livewire-POST og
    // udleverer praecis det indhold gaten skal beskytte.
    $this->withSession(['metis_lookup_count' => 5]);

    $svar = $this->get('/lookup/cvr/37792594')->assertOk();

    $svar->assertDontSee('metis-company-info', false)
        ->assertDontSee('metis-company-overview', false)
        ->assertDontSee('metis-company-structure', false);
});

it('🚨 gater ogsaa CPR-ruten', function () {
    // CPR-opslaget logges bevidst ikke, men det skal stadig KOSTE. Ellers ville
    // netop den rute der baerer persondata vaere gratis og ubegraenset.
    $this->withSession(['metis_lookup_count' => 1]);

    $this->get('/lookup/cpr/311278-1234')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

it('🚨 et CPR-opslag TAELLER, selv om det ikke gemmes i historikken', function () {
    // 🪤 Logning og kvote er to forskellige spoergsmaal. Taelleren staar uden
    // for `if (! $erCpr)` netop derfor.
    $this->get('/lookup/cpr/311278-1234')->assertOk();

    expect(session('metis_lookup_count'))->toBe(1);
});

it('lader en verificeret email passere gaten', function () {
    $this->withSession([
        'metis_lookup_count' => 99,
        'metis_verified_email' => 'test@frankston.io',
    ]);

    $this->get('/lookup/cvr/37792594')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');
});

it('lader en pilot-token passere gaten', function () {
    // Rasmus-piloten maa ikke rammes af den offentlige betas kvoter.
    $this->withSession(['metis_lookup_count' => 99, 'metis_user_token' => 'pilot-abc']);

    $this->get('/lookup/cvr/37792594')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');
});

it('gater IKKE naar gating er slaaet fra (embedded mode)', function () {
    config()->set('metis.gating.enabled', false);
    $this->withSession(['metis_lookup_count' => 99]);

    $this->get('/lookup/cvr/37792594')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');
});

it('🪤 et gatet opslag bruger ikke af kvoten', function () {
    // Ellers ville et blokeret opslag koste brugeren en visning han aldrig fik.
    // 🪤 Historik-skrivningen ligger efter samme `return`, saa den udebliver af
    // samme grund — men den assertion kraever en database og hoerer hjemme i en
    // test der har én.
    $this->withSession(['metis_lookup_count' => 1]);

    $this->get('/lookup/cvr/37792594')->assertOk();

    expect(session('metis_lookup_count'))->toBe(1);
});
