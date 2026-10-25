<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;

uses(RefreshDatabase::class);

/**
 * Koncernniveau (ERST Bilag 1, Krav 1.1): "Tæl selskaber / Tæl koncerner".
 * Reglen bag tallet staar i registry-api (App\Services\Cvr\Koncern) og paa
 * metodesiden; her proeves at valget sendes, og at etiketten foelger det
 * niveau datalaget FAKTISK talte paa.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    config()->set('metis.gating.enabled', false);
    Http::preventStrayRequests();
});

function fakeKoncernSvar(?string $niveau): void
{
    Http::fake([
        '*/company-segmentation/export-link' => Http::response(['url' => 'https://registry.test/taelling.csv?signature=b']),
        '*/company-segmentation' => Http::response([
            'data' => [['key' => '751', 'label' => null, 'count' => 40]],
            'meta' => array_filter(['total' => 90, 'niveau' => $niveau]),
        ]),
    ]);
}

function erTaelling($r): bool
{
    return str_ends_with($r->url(), '/company-segmentation');
}

it('taeller selskaber som standard og siger det', function () {
    fakeKoncernSvar('selskab');

    Livewire::test(CompanySegmentation::class)
        ->assertSet('niveau', 'selskab')
        ->assertSet('vistNiveau', 'selskab')
        ->assertSee('selskaber i alt med de valgte afgrænsninger')
        ->assertDontSee('koncerner i alt med de valgte afgrænsninger')
        ->assertSee('Tæl koncerner');

    Http::assertSent(fn ($r) => erTaelling($r) && ($r->data()['niveau'] ?? null) === 'selskab');
});

it('sender niveau=koncern og skifter etiketten', function () {
    fakeKoncernSvar('koncern');

    Livewire::test(CompanySegmentation::class)
        ->set('niveau', 'koncern')
        ->assertSet('vistNiveau', 'koncern')
        ->assertSee('koncerner i alt med de valgte afgrænsninger')
        ->assertSeeHtml('data-koncern-note');

    Http::assertSent(fn ($r) => erTaelling($r) && ($r->data()['niveau'] ?? null) === 'koncern');
});

it('🪤 siger selskaber naar datalaget ikke har talt koncerner', function () {
    // Et datalag uden koncernniveau ignorerer parameteren og sender ingen
    // meta.niveau. Saa er tallene selskaber, uanset hvad brugeren valgte.
    fakeKoncernSvar(null);

    Livewire::test(CompanySegmentation::class)
        ->set('niveau', 'koncern')
        ->assertSet('vistNiveau', 'selskab')
        ->assertSee('selskaber i alt med de valgte afgrænsninger')
        ->assertDontSee('koncerner i alt med de valgte afgrænsninger');
});

it('dropper et ukendt niveau fra URL-en', function () {
    fakeKoncernSvar('selskab');

    Livewire::withQueryParams(['niveau' => 'gruppe'])
        ->test(CompanySegmentation::class)
        ->assertSet('niveau', 'selskab');

    Http::assertSent(fn ($r) => erTaelling($r) && ($r->data()['niveau'] ?? null) === 'selskab');
});

it('tager niveau fra URL-en, saa et delt link viser samme tal', function () {
    fakeKoncernSvar('koncern');

    Livewire::withQueryParams(['niveau' => 'koncern'])
        ->test(CompanySegmentation::class)
        ->assertSet('niveau', 'koncern');

    Http::assertSent(fn ($r) => erTaelling($r) && ($r->data()['niveau'] ?? null) === 'koncern');
});

it('sender det VISTE niveau med til CSV-linket', function () {
    fakeKoncernSvar('koncern');

    Livewire::test(CompanySegmentation::class)
        ->set('niveau', 'koncern')
        ->call('hentCsv')
        ->assertRedirect('https://registry.test/taelling.csv?signature=b');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/company-segmentation/export-link')
        && ($r->data()['niveau'] ?? null) === 'koncern');
});

it('kalder kapitaldaekningen koncerner paa koncernniveau', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => '751', 'label' => null, 'count' => 40]],
        'meta' => ['total' => 90, 'niveau' => 'koncern', 'uden_kapitalhistorik' => 12, 'uden_kendt_beloeb' => 3],
    ])]);

    Livewire::test(CompanySegmentation::class)
        ->set('niveau', 'koncern')
        ->set('rejstMax', 1000000)
        ->assertSee('12 koncerner i udvalget er udeladt')
        ->assertSee('3 koncerner er udeladt');
});

it('kalder kapitaldaekningen selskaber paa selskabsniveau', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => '751', 'label' => null, 'count' => 40]],
        'meta' => ['total' => 90, 'niveau' => 'selskab', 'uden_kapitalhistorik' => 12, 'uden_kendt_beloeb' => 3],
    ])]);

    Livewire::test(CompanySegmentation::class)
        ->set('rejstMax', 1000000)
        ->assertSee('12 selskaber i udvalget er udeladt')
        ->assertSee('3 selskaber er udeladt');
});

/**
 * 🪤 Paa koncernniveau overlapper grupperne (en koncern taeller i hver gruppe,
 * den har selskaber i), saa andelene summede til over 100 % og lignede en
 * regnefejl (114,5 % maalt 25/10-2026). Ingen andel dér; paa selskabsniveau
 * summer de til 100 % og vises.
 */
function fakeAndelSvar(string $niveau): void
{
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => '751', 'label' => null, 'count' => 40]],
        'meta' => ['total' => 90, 'niveau' => $niveau, 'uden_segmentering' => 50],
    ])]);
}

it('viser andelen paa selskabsniveau', function () {
    fakeAndelSvar('selskab');

    Livewire::test(CompanySegmentation::class)
        ->assertSeeHtml('>Andel</th>')
        ->assertSee('44,4 %')
        ->assertSee('55,6 %');
});

it('🪤 viser ingen andel paa koncernniveau, hvor grupperne overlapper', function () {
    fakeAndelSvar('koncern');

    Livewire::test(CompanySegmentation::class)
        ->set('niveau', 'koncern')
        ->assertSet('vistNiveau', 'koncern')
        ->assertSee('40')
        ->assertDontSeeHtml('>Andel</th>')
        ->assertDontSee('44,4 %')
        ->assertDontSee('55,6 %')
        ->assertSeeHtml('data-koncern-note');
});
