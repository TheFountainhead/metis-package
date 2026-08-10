<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * "Ingen pantebreve" vs. "ikke undersøgt".
 *
 * 🚨 Sektionen skrev "No mortgages found" på BEGGE — en falsk påstand om
 * fravær, den værste fejlmodus i kreditvurdering. Brugeren tror ejendommen er
 * gældfri; sandheden er at vi ikke har kigget.
 *
 * 🪤 Målt 10/8: efter adresse-backfillen blev 1.105.049 ejendomme crawl-klare
 * på én gang, men crawlen tager ~60 døgn (Tinglysningen rate-limiter til 12,3
 * opslag/min). I hele den periode ville u-crawlede ejendomme se gældfri ud.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    Http::preventStrayRequests();
});

function fakeEjendom(array $property): void
{
    Http::fake(['*' => Http::response(['data' => ['property' => $property]])]);
}

it('🚨 siger "ikke hentet endnu" naar ejendommen ikke er crawlet', function () {
    fakeEjendom(['mortgages' => [], 'total_debt' => 0, 'tinglysning_synced_at' => null]);

    Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])
        ->assertSee('ikke hentet for denne ejendom endnu')
        ->assertDontSee('No mortgages found');
});

it('🚨 siger EKSPLICIT at det ikke betyder gaeldfri', function () {
    // Uden denne linje kunne "ikke hentet" stadig laeses som "der er intet".
    fakeEjendom(['mortgages' => [], 'total_debt' => 0, 'tinglysning_synced_at' => null]);

    Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])
        ->assertSee('ikke at ejendommen er gældfri');
});

it('siger "ingen pantebreve" naar ejendommen ER undersoegt', function () {
    // 🪤 Modstykket. Uden denne ville "ikke hentet paa alt" bestaa testen
    // ovenfor — og vi ville aldrig turde sige at en ejendom er gaeldfri.
    fakeEjendom([
        'mortgages' => [], 'total_debt' => 0,
        'tinglysning_synced_at' => '2026-08-10T12:00:00+00:00',
    ]);

    Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])
        ->assertSee('No mortgages found')
        ->assertDontSee('ikke hentet');
});

it('🪤 antager UNDERSOEGT naar API et slet ikke sender feltet', function () {
    // Bagudkompatibilitet: et aeldre registry-api uden feltet maa ikke faa
    // hver eneste ejendom til at se u-undersoegt ud.
    fakeEjendom(['mortgages' => [], 'total_debt' => 0]);

    Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])
        ->assertSet('erUndersoegt', true)
        ->assertSee('No mortgages found');
});

it('viser pantebreve normalt naar der ER nogen', function () {
    fakeEjendom([
        'mortgages' => [['creditor' => 'Realkredit Danmark', 'principal' => 1000000]],
        'total_debt' => 1000000,
        'tinglysning_synced_at' => '2026-08-10T12:00:00+00:00',
    ]);

    Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])
        ->assertSee('Realkredit Danmark')
        ->assertDontSee('ikke hentet');
});
