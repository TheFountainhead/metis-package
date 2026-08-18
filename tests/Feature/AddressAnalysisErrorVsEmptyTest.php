<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressMortgages;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Et FEJLET opslag maa aldrig rendere som "ingen data".
 *
 * resolveAddressAnalysis() returnerede `[]` for BAADE fejl og tom, saa de 12
 * address-sektioner ikke kunne skelne "vi spurgte og fik intet" fra "vi kunne
 * ikke spoerge". Observeret i prod 18/8: én adresse uden postnummer gav 422,
 * og alle 12 sektioner skrev "Ingen data fundet".
 *
 * 🚨 "Ingen pantebreve fundet" laeses som GAELDFRIHED. I en kreditvurdering er
 * det en konklusion nogen handler paa — den vaerste falske benaegtelse i due
 * diligence.
 *
 * 🪤 Og fejlen blev CACHET I 24 TIMER, saa ét daarligt svar laase loegnen fast
 * et doegn. Kodebasens egen regel siger det modsatte (#134).
 */
beforeEach(fn () => Cache::flush());

it('🚨 giver en FEJL videre i stedet for en tom liste', function () {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $svar = app(RegistryApi::class)->resolveAddressAnalysis('Søndergade 43A');

    // Foer: [] — ikke til at skelne fra "ejendommen har ingen data".
    expect($svar)->toHaveKey('error')
        ->and($svar['status'])->toBe(422)
        ->and($svar)->not->toHaveKey('property');
});

it('🪤 cacher ALDRIG en fejl — ét daarligt svar maa ikke laase et doegn', function () {
    // Foerste kald fejler, andet lykkes. Uden fixet ville 24t-cachen
    // servere fejlen som "ingen data" resten af doegnet.
    Http::fakeSequence()
        ->push(['message' => 'Unprocessable'], 422)
        ->push(['data' => ['property' => ['address' => 'Søndergade 43A, 4653 Karise', 'mortgages' => [['principal' => 500000]]]]], 200);

    $api = app(RegistryApi::class);

    expect($api->resolveAddressAnalysis('Søndergade 43A'))->toHaveKey('error');
    expect($api->resolveAddressAnalysis('Søndergade 43A'))->toHaveKey('property');
});

it('cacher stadig et AEGTE tomt svar — det er ikke en fejl', function () {
    // 200 uden property = ejendommen findes, men vi har ingen data.
    // Den tilstand maa gerne caches; det er kun fejl der ikke maa.
    Http::fakeSequence()
        ->push(['data' => ['property' => null]], 200)
        ->push(['data' => ['property' => ['mortgages' => [['principal' => 1]]]]], 200);

    $api = app(RegistryApi::class);

    expect($api->resolveAddressAnalysis('Tomvej 1, 1000 By'))->toBe([]);
    // Andet kald rammer cachen, ikke det nye svar.
    expect($api->resolveAddressAnalysis('Tomvej 1, 1000 By'))->toBe([]);
});

it('🚨 siger IKKE "ingen pantebreve" naar opslaget fejlede', function () {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $html = Livewire::test(AddressMortgages::class, ['query' => 'Søndergade 43A'])->html();

    // Den falske benaegtelse maa vaere VAEK ...
    expect($html)->not->toContain('No mortgages found.')
        // ... og erstattet af noget der siger hvad der faktisk skete.
        ->and($html)->toContain('opslaget mislykkedes');
});

it('siger det praecist naar adressen er FLERTYDIG (422), ikke bare "noget gik galt"', function () {
    // Brugeren kan selv rette DEN fejl ved at tilfoeje postnummer — men kun
    // hvis beskeden siger det. Maalt: "Søndergade 43A" findes mindst 5 steder.
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $html = Livewire::test(AddressMortgages::class, ['query' => 'Søndergade 43A'])->html();

    expect($html)->toContain('prøv med postnummer');
});

it('viser stadig "No mortgages found." naar ejendommen ER undersoegt og gaeldfri', function () {
    // Guarden maa ikke goere den AEGTE tomme tilstand utilgaengelig — en
    // faktisk gaeldfri ejendom skal stadig kunne sige det.
    Http::fake(['*property/analysis*' => Http::response(['data' => ['property' => [
        'address' => 'Gaeldfri Alle 1, 1000 By',
        'mortgages' => [],
        'tinglysning_synced_at' => '2026-08-18T00:00:00Z',
    ]]], 200)]);

    $html = Livewire::test(AddressMortgages::class, ['query' => 'Gaeldfri Alle 1, 1000 By'])->html();

    expect($html)->toContain('No mortgages found.')
        ->and($html)->not->toContain('opslaget mislykkedes');
});

it('🚨 siger IKKE "ingen ejerdata" naar opslaget fejlede', function () {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $html = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\AddressOwners::class,
        ['query' => 'Søndergade 43A'])->html();

    expect($html)->not->toContain('No owner data found.')
        ->and($html)->toContain('opslaget mislykkedes');
});

it('🚨 siger IKKE "ingen vurderingsdata" naar opslaget fejlede', function () {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $html = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\AddressValuation::class,
        ['query' => 'Søndergade 43A'])->html();

    expect($html)->not->toContain('No valuation data found.')
        ->and($html)->toContain('opslaget mislykkedes');
});

it('viser stadig den AEGTE tomme tilstand for ejere naar kaldet lykkes', function () {
    // Guarden maa ikke goere "ingen ejere" utilgaengelig — kun uaerlig.
    Http::fake(['*property/analysis*' => Http::response(['data' => ['property' => [
        'address' => 'Tomvej 1, 1000 By', 'owners' => [],
    ]]], 200)]);

    $html = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\AddressOwners::class,
        ['query' => 'Tomvej 1, 1000 By'])->html();

    expect($html)->toContain('No owner data found.')
        ->and($html)->not->toContain('opslaget mislykkedes');
});
