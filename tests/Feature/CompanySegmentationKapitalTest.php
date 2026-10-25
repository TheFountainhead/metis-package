<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;

uses(RefreshDatabase::class);

/**
 * Kapitalfiltre (ERST krav 2, udviklingsstadier). Datalaget er registry-api
 * #392; her vaernes ledningerne: felt -> parameter, meta -> skaerm.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    config()->set('metis.gating.enabled', false);
    Http::preventStrayRequests();
});

it('sender hvert kapitalfelt som registry-api-parameteren', function (string $felt, mixed $vaerdi, string $noegle) {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::test(CompanySegmentation::class)->set($felt, $vaerdi);

    Http::assertSent(fn ($r) => ($r->data()[$noegle] ?? null) === $vaerdi);
})->with([
    'antal min' => ['kapitalforhoejelserMin', 0, 'kapitalforhoejelser_min'],
    'antal maks' => ['kapitalforhoejelserMax', 3, 'kapitalforhoejelser_max'],
    'rejst min' => ['rejstMin', 1000000, 'rejst_min'],
    'rejst maks' => ['rejstMax', 5000000, 'rejst_max'],
    'seneste fra' => ['senesteForhoejelseFra', '2022-01-01', 'seneste_forhoejelse_fra'],
    'seneste til' => ['senesteForhoejelseTil', '2025-12-31', 'seneste_forhoejelse_til'],
]);

it('sender ingen kapitalparametre som standard', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::test(CompanySegmentation::class);

    Http::assertNotSent(fn ($r) => array_intersect_key($r->data(), array_flip([
        'kapitalforhoejelser_min', 'kapitalforhoejelser_max', 'rejst_min', 'rejst_max',
        'seneste_forhoejelse_fra', 'seneste_forhoejelse_til',
    ])) !== []);
});

it('laeser kapitalfiltrene fra URL-en og dropper et negativt tal', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::withQueryParams(['kapitalforhoejelser_min' => '2', 'rejst_maks' => '-5', 'seneste_forhoejelse_fra' => '2021-01-01'])
        ->test(CompanySegmentation::class)
        ->assertSet('kapitalforhoejelserMin', 2)
        ->assertSet('rejstMax', null);

    Http::assertSent(fn ($r) => ($r->data()['kapitalforhoejelser_min'] ?? null) === 2
        && ($r->data()['seneste_forhoejelse_fra'] ?? null) === '2021-01-01'
        && ! array_key_exists('rejst_max', $r->data()));
});

it('nulstil fjerner kapitalfiltrene', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    $c = Livewire::test(CompanySegmentation::class)
        ->set('kapitalforhoejelserMin', 1)
        ->set('rejstMin', 10)
        ->set('senesteForhoejelseTil', '2024-01-01')
        ->call('nulstil');

    expect($c->instance()->filtre())->toBe([]);
});

it('viser hvor mange der er udeladt fordi kapitalhistorikken ikke er hentet', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => '101', 'label' => null, 'count' => 40]],
        'meta' => ['total' => 40, 'kapital_hentet' => true, 'uden_kapitalhistorik' => 12345],
    ])]);

    Livewire::test(CompanySegmentation::class)
        ->set('rejstMin', 5000000)
        ->assertSet('udenKapitalhistorik', 12345)
        ->assertSeeHtml('data-kapitaldaekning')
        ->assertSee('12.345 selskaber i udvalget er udeladt');
});

it('viser ingen kapitaldaekning uden kapitalfilter', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 5, 'kapital_hentet' => false, 'uden_kapitalhistorik' => null]])]);

    Livewire::test(CompanySegmentation::class)
        ->assertSet('udenKapitalhistorik', null)
        ->assertDontSeeHtml('data-kapitaldaekning');
});

it('kan fordele paa kapitalforhoejelser med "Kapitalhistorik ikke hentet" som uden-linje', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [
            ['key' => '0', 'label' => 'Ingen efter stiftelse', 'count' => 60],
            ['key' => '7-plus', 'label' => '7 eller flere', 'count' => 10],
        ],
        'meta' => ['total' => 100, 'uden_segmentering' => 30],
    ])]);

    $c = Livewire::test(CompanySegmentation::class)->set('groupBy', 'kapitalforhoejelser');

    $c->assertSeeHtml('data-uden-noegle')
        ->assertSee('Kapitalhistorik ikke hentet')
        ->assertSee('30,0 %');
    // Navnet ogsaa uden API'ets label, og ingen intern noegle ved siden af.
    expect($c->instance()->etiket(['key' => '7-plus', 'label' => null]))->toBe('7 eller flere')
        ->and(CompanySegmentation::VIS_KODE)->not->toContain('kapitalforhoejelser');

    Http::assertSent(fn ($r) => ($r->data()['group_by'] ?? null) === 'kapitalforhoejelser');
});

it('har de samme kapitalgruppe-noegler som registry-api', function () {
    // Spejl af registry-api CompanySegmentation::KAPITAL_ETIKETTER (#392).
    expect(CompanySegmentation::KAPITALGRUPPER)->toBe([
        '0' => 'Ingen efter stiftelse', '1' => '1', '2-3' => '2-3', '4-6' => '4-6', '7-plus' => '7 eller flere',
    ]);
});

it('viser kapitalfiltrene under deres egen overskrift uden stadie-betegnelser', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('Kapital (udviklingsstadie)')
        ->assertSeeHtml('wire:model.change.number="kapitalforhoejelserMin"')
        ->assertSeeHtml('wire:model.change.number="rejstMax"')
        ->assertSeeHtml('wire:model.change="senesteForhoejelseFra"')
        ->assertSee('nedre grænse')
        ->assertDontSee('seed');
});

it('🚨 et array i en kapitaldato fra URL-en giver ikke 500', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::withQueryParams(['seneste_forhoejelse_fra' => ['a', 'b'], 'seneste_forhoejelse_til' => ['c']])
        ->test(CompanySegmentation::class)
        ->assertSet('senesteForhoejelseFra', '')
        ->assertSet('senesteForhoejelseTil', '');
});
