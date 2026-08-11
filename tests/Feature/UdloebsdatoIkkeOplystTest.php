<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Udløbsdato: "ikke oplyst", ikke en bar bindestreg.
 *
 * 🚨 UNDERSØGT 11/8 — feltet er tomt på 100% af 1,27 mio. pantebreve, og det
 * er IKKE et efterslæb der crawler sig væk:
 *
 *   1. `maturity_date` skrives aldrig i registry-api
 *   2. `ssl/ejdsummarisk` leverer kun `TinglysningsDato` +
 *      `SenestPaategnetDato` ("senest påtegnet" ≠ udløb)
 *   3. e-TL's egen `model:PantebrevFastEjendom` (34 elementer) har intet
 *      udløbs-/løbetidsfelt. `HaeftelseVilkaarRente` er vilkår for RENTEN;
 *      `HaeftelseSaerligBestemmelse` er fritekst og forekommer 0 gange i
 *      2.000 stikprøvede prod-pantebreve.
 *
 * 🪤 Derfor må kolonnen ikke vise en bar "-": i en gældstabel læses en
 * bindestreg som "ingen udløbsdato" — altså et stående lån — og ikke som "vi
 * har ikke oplysningen". Det er samme fejlklasse som at skrive "ingen
 * pantebreve" om en ejendom vi aldrig har undersøgt: en påstand om fravær,
 * hvor sandheden er manglende viden.
 *
 * 🪤 Rente beholder sin bindestreg. Den mangler på 30,1% og FINDES for
 * resten, så dér er tomheden en egenskab ved det enkelte pantebrev. Udløb
 * mangler på 100% fordi kilden aldrig har feltet — to forskellige slags
 * tomhed, to forskellige svar.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    Http::preventStrayRequests();
});

function fakePant(array $mortgages): void
{
    Http::fake(['*' => Http::response(['data' => ['property' => [
        'mortgages' => $mortgages,
        'total_debt' => 1000000,
        'tinglysning_synced_at' => '2026-08-11T12:00:00+00:00',
    ]]])]);
}

it('🚨 skriver "ikke oplyst" i stedet for en bar bindestreg ved manglende udløb', function () {
    fakePant([['creditor' => 'Nykredit', 'principal' => 1000000, 'maturity_date' => null]]);

    Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])
        ->assertSee('ikke oplyst');
});

it('🚨 forklarer HVORFOR udløb mangler, saa det ikke laeses som "staaende laan"', function () {
    // Uden forklaringen ville en investor kunne konkludere at laanet aldrig
    // forfalder. Kilden har simpelthen ikke feltet.
    fakePant([['creditor' => 'Nykredit', 'principal' => 1000000, 'maturity_date' => null]]);

    Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])
        ->assertSee('Tinglysningen oplyser ikke udløbsdato');
});

it('viser en RIGTIG udloebsdato hvis den nogensinde kommer', function () {
    // 🪤 Modstykket. Uden denne ville "ikke oplyst paa alt" bestaa testene
    // ovenfor, og en fremtidig datakilde ville blive skjult af vores egen tekst.
    fakePant([['creditor' => 'Nykredit', 'principal' => 1000000, 'maturity_date' => '2035-06-30']]);

    Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])
        ->assertSee('2035-06-30')
        ->assertDontSee('ikke oplyst');
});

it('🪤 beholder bindestreg paa RENTE — den mangler kun paa 30,1%', function () {
    // Rente er en anden slags tomhed: den findes for de fleste pantebreve, saa
    // en tom celle er en egenskab ved DETTE pantebrev, ikke ved datakilden.
    fakePant([['creditor' => 'Nykredit', 'principal' => 1000000, 'interest_rate' => null, 'maturity_date' => null]]);

    $html = Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])->html();

    // Praecis én "ikke oplyst" (udloeb) — renten har stadig sin bindestreg.
    expect(substr_count($html, 'ikke oplyst'))->toBe(1);
});
