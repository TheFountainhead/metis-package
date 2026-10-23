<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;

uses(RefreshDatabase::class);

/**
 * 🚨 TALLET HALTEDE ÉN AENDRING BAGEFTER (prod 13/10-2026).
 *
 * Felterne var bundet med `wire:model.blur` + `wire:change="segmentér"`.
 * Kom "change" foer "blur", regnede serveren paa den GAMLE vaerdi, og den nye
 * kom bagefter uden genberegning. Maalt i Chrome: en dato tastet ind gav 8 x
 * `segmentér` med den gamle dato; Enter i kommunefeltet og piletaster i
 * aarsvaerk gjorde det samme. Skaermen viste 43.093 for stiftet fra
 * 2021-01-01, hvor registret giver 36.842 (43.093 hoerer til 2020-01-01).
 *
 * 🔑 Kontrakten: naar et filterfelt FAAR sin vaerdi, regner komponenten selv.
 * Den maa ikke vaere afhaengig af at et separat browser-event kommer i den
 * rigtige raekkefoelge.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    // Gaten er slaaet fra i TestCase; her eksplicit, saa testene ikke
    // bliver roede af en anden grund, hvis den default aendres.
    config()->set('metis.gating.enabled', false);
    Http::preventStrayRequests();
    Http::fake([
        '*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
    ]);
});

it('regner paa den nye vaerdi saa snart et filterfelt aendres', function (string $felt, mixed $vaerdi, string $noegle) {
    Livewire::test(CompanySegmentation::class)->set($felt, $vaerdi);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'company-segmentation')
        && ($request->data()[$noegle] ?? null) === $vaerdi);
})->with([
    'stiftet fra' => ['foundedFrom', '2020-01-01', 'founded_from'],
    'stiftet til' => ['foundedTo', '2024-12-31', 'founded_to'],
    'branche' => ['industryPrefix', '62', 'industry_prefix'],
    'kommune' => ['municipalityCode', '101', 'municipality_code'],
    'region' => ['region', '1084', 'region'],
    'vertikal' => ['vertikal', 'software', 'vertikal'],
    'aarsvaerk min' => ['fteMin', 2, 'fte_min'],
    'aarsvaerk maks' => ['fteMax', 9, 'fte_max'],
]);

it('binder filterfelterne saa vaerdien sendes med den samme change, der regner', function () {
    // 🪤 Livewire::test() kender ikke browserens event-raekkefoelge, saa den
    // kan ikke selv se fejlen. Markup'en er derfor ogsaa vaernet: intet input
    // maa have en `.blur`-binding eller en separat change-handler.
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/company-segmentation.blade.php');
    preg_match_all('/<input\b[^>]*>/s', $view, $inputs);

    foreach ($inputs[0] as $input) {
        expect($input)->not->toMatch('/wire:model\.[\w.]*blur|wire:change|x-on:change|@change/');
    }

    // 🔑 Hvert felt bundet med `.change` SKAL regne. Tilfoejes et nyt
    // filterfelt i markup'en uden at komme paa listen, kommer fejlen tilbage
    // stille. Listen laeses fra komponenten, ikke skrevet af her.
    preg_match_all('/wire:model\.change(?:\.number)?="(\w+)"/', $view, $bundne);
    $regner = (new ReflectionClassConstant(CompanySegmentation::class, 'FILTERFELTER'))->getValue();

    // 🪤 Aarsvaerk SKAL have `.number`: uden det sender browseren "5", serveren
    // normaliserer til 5, og Livewire ser forskellen som en aendring og
    // skriver vaerdien tilbage i feltet midt i brugerens piletryk (review #204).
    expect($view)->toContain('wire:model.change.number="fteMin"')
        ->and($view)->toContain('wire:model.change.number="fteMax"');

    expect($bundne[1])->not->toBeEmpty()
        ->and(array_values(array_diff($bundne[1], $regner)))->toBe([])
        ->and(array_values(array_diff($regner, $bundne[1])))->toBe([]);
});

it('regner kun én gang naar et felt med eget hook aendres', function (string $felt, mixed $vaerdi) {
    // 🪤 `groupBy`, `ivaerksaetter` og `excludeHolding` har egne hooks der
    // regner. Fanger det generelle hook dem ogsaa, gaar der to kald til
    // registret pr. klik.
    $c = Livewire::test(CompanySegmentation::class);
    $foer = count(Http::recorded());

    $c->set($felt, $vaerdi);

    expect(count(Http::recorded()) - $foer)->toBe(1);
})->with([
    'grupper paa' => ['groupBy', 'company_type'],
    'ivaerksaetter' => ['ivaerksaetter', true],
    'uden holding' => ['excludeHolding', true],
    'stiftet fra' => ['foundedFrom', '2020-01-01'],
]);
