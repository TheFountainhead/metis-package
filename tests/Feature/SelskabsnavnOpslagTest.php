<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Opslag paa et SELSKABSNAVN maa ikke give en tom side.
 *
 * 🚨 MAALT 11/8: Larnaes soegte "A.P. Møller - Mærsk A/S" to gange med fem
 * sekunders mellemrum 10/8 kl. 20:32:55 og 20:33:00, og stoppede derefter med
 * at teste. Reproduceret: `/lookup/company_name/<navn>` gav en side med
 * overskriften "Personopslag" og INTET indhold.
 *
 * Aarsagen: `lookup.blade.php` har kun grene for `cvr`, `cpr`, `address` og
 * `person`, og ingen `@else`. En ukendt type falder igennem til en tom `<div>`.
 * `company_name` er tiltaenkt som en FORSLAGSLISTE, men typen kan ende i URL'en.
 *
 * 🪤 En tom side er vaerre end en fejlmeddelelse: brugeren kan ikke se om
 * selskabet ikke findes, om det er en fejl, eller om siden stadig loader. En
 * gentagen soegning fem sekunder efter er praecis den adfaerd det fremkalder.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', false);
    Http::preventStrayRequests();
});

it('🚨 sender ét entydigt traef videre til selskabets CVR-side', function () {
    Http::fake(['*' => Http::response(['data' => ['companies' => [
        ['cvr' => '22756214', 'name' => 'A.P. Møller - Mærsk A/S'],
    ]]])]);

    Livewire::test('metis-lookup', ['type' => 'company_name', 'query' => 'A.P. Møller - Mærsk A/S'])
        ->assertRedirect(route('metis.lookup', ['type' => 'cvr', 'query' => '22756214']));
});

it('🚨 gaetter IKKE naar der er flere traef', function () {
    // At vaelge den foerste ville sende brugeren til et forkert selskab uden
    // at fortaelle det. Forslagslisten paa forsiden lader ham vaelge selv.
    Http::fake(['*' => Http::response(['data' => ['companies' => [
        ['cvr' => '11111111', 'name' => 'Mærsk A/S'],
        ['cvr' => '22222222', 'name' => 'Mærsk Holding ApS'],
    ]]])]);

    Livewire::test('metis-lookup', ['type' => 'company_name', 'query' => 'Mærsk'])
        ->assertRedirect(route('metis.home', ['q' => 'Mærsk']));
});

it('🪤 lander ikke paa en tom side naar selskabet slet ikke findes', function () {
    Http::fake(['*' => Http::response(['data' => ['companies' => []]])]);

    Livewire::test('metis-lookup', ['type' => 'company_name', 'query' => 'Findes Ikke ApS'])
        ->assertRedirect(route('metis.home', ['q' => 'Findes Ikke ApS']));
});

it('🪤 koster ikke en kvote at blive videresendt', function () {
    // Redirecten sker FOER kvote-gaten: brugeren har ikke set data endnu.
    config()->set('metis.gating.enabled', true);
    session(['metis_lookup_count' => 99]);

    Http::fake(['*' => Http::response(['data' => ['companies' => [
        ['cvr' => '22756214', 'name' => 'A.P. Møller - Mærsk A/S'],
    ]]])]);

    Livewire::test('metis-lookup', ['type' => 'company_name', 'query' => 'A.P. Møller - Mærsk A/S'])
        ->assertRedirect(route('metis.lookup', ['type' => 'cvr', 'query' => '22756214']));
});
