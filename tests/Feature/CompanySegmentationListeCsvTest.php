<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Selskabslisten bag et tal som Excel-fil (ERST Bilag 1: "ubegraenset
 * udtraek til Excel"). Tidligere kunne kun TAELLINGERNE hentes.
 *
 * 🚨 Samme regler som taellings-CSV'en: linket hentes paa serveren med vores
 * noegle, kun for piloter, og der redirectes kun til registry-api'ets vaert.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    config()->set('metis.gating.enabled', false);
    Cache::flush();
    Http::preventStrayRequests();
});

function fakeListeCsv(string $url = 'https://registry.test/v1/company-segmentation-companies.csv?signature=abc'): void
{
    Http::fake([
        '*/company-segmentation/companies/export-link' => Http::response(['url' => $url, 'expires_in' => 60]),
        '*/company-segmentation/companies' => Http::response([
            'data' => [['cvr' => '40447024', 'name' => 'Alfa ApS', 'max_fte' => 4]],
            'meta' => ['total' => 6924, 'page' => 1, 'per_page' => 50, 'last_page' => 139],
        ]),
        '*/company-segmentation' => Http::response([
            'data' => [['key' => '751', 'label' => null, 'count' => 6924]],
            'meta' => ['total' => 117382],
        ]),
    ]);
}

function erListeCsvLink($r): bool
{
    return str_contains($r->url(), '/company-segmentation/companies/export-link');
}

it('henter den aabne gruppes selskaber som CSV med SAMME gruppe og filtre som listen', function () {
    fakeListeCsv();

    Livewire::test(CompanySegmentation::class)
        ->set('ivaerksaetter', true)
        ->assertDontSeeHtml('wire:click="hentSelskaberCsv"')
        ->call('visSelskaber', '751')
        // Knappen sidder i listens hoved; taellings-knappen har samme tekst.
        ->assertSeeHtml('wire:click="hentSelskaberCsv"')
        ->call('hentSelskaberCsv')
        ->assertRedirect('https://registry.test/v1/company-segmentation-companies.csv?signature=abc');

    Http::assertSent(fn ($r) => erListeCsvLink($r)
        && $r['group_by'] === 'municipality_code'
        && $r['group_key'] === '751'
        && $r['ivaerksaetter'] === true);
});

it('henter hele det viste udvalg uden gruppenoegle', function () {
    fakeListeCsv();

    Livewire::test(CompanySegmentation::class)
        ->set('inklOphoerte', true)
        ->assertSee('Hent selskabslisten (CSV)')
        ->assertSeeHtml('wire:click="hentUdvalgCsv"')
        ->call('hentUdvalgCsv')
        ->assertRedirect('https://registry.test/v1/company-segmentation-companies.csv?signature=abc');

    Http::assertSent(fn ($r) => erListeCsvLink($r)
        && ! array_key_exists('group_key', $r->data())
        && $r['inkl_ophoerte'] === true);
});

it('henter intet naar ingen gruppe er aaben', function () {
    fakeListeCsv();

    Livewire::test(CompanySegmentation::class)
        ->call('hentSelskaberCsv')
        ->assertNoRedirect();

    Http::assertNotSent(fn ($r) => erListeCsvLink($r));
});

it('eksporterer ikke hele udvalget foer der ER et resultat paa skaermen', function () {
    Http::fake(['*/company-segmentation' => Http::response([], 500)]);

    Livewire::test(CompanySegmentation::class)
        ->call('hentUdvalgCsv')
        ->assertNoRedirect()
        ->assertSee('Hent et resultat frem');

    Http::assertNotSent(fn ($r) => erListeCsvLink($r));
});

it('🚨 redirecter ALDRIG til en fremmed vaert, hverken fra listen eller udvalget', function () {
    fakeListeCsv('https://ondsindet.example.com/pwn');

    Livewire::test(CompanySegmentation::class)
        ->call('hentUdvalgCsv')
        ->assertNoRedirect()
        ->assertSee('kunne ikke dannes')
        ->call('visSelskaber', '751')
        ->call('hentSelskaberCsv')
        ->assertNoRedirect()
        ->assertSet('selskabFejl', 'Udtrækket kunne ikke dannes. Prøv igen, eller skriv til support@frankston.io.');
});

it('🚨 datalaget danner intet liste-link for en ikke-pilot, uden et HTTP-kald', function () {
    fakeListeCsv();
    config()->set('metis.gating.enabled', true);
    $this->withSession(['metis_verified_email' => 'kunde@firma.dk', 'metis_user_token' => '1|x']);

    expect(app(RegistryApi::class)->segmentationCompaniesExportLink('municipality_code', '751'))->toBeNull()
        ->and(app(RegistryApi::class)->segmentationCompaniesExportLink('municipality_code', null))->toBeNull();

    Http::assertNothingSent();
});

it('🚨 en ikke-pilot faar intet udtraek med en PILOTS snapshot (hentUdvalgCsv)', function () {
    fakeListeCsv();
    config()->set('metis.gating.enabled', true);
    $this->withSession(['metis_user_token' => '1|aegte', 'metis_pilot_account_id' => 7]);
    $html = $this->get('/segmentering')->assertOk()->assertSee('117.382')->getContent();
    preg_match('/wire:snapshot="([^"]*company-segmentation[^"]*)"/', $html, $m);
    $snap = html_entity_decode($m[1], ENT_QUOTES);

    $this->flushSession();

    $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snap,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => 'hentUdvalgCsv', 'params' => []]],
        ]],
    ])->assertOk()->assertDontSee('signature=');

    Http::assertNotSent(fn ($r) => erListeCsvLink($r));
});

it('🚨 taellings- og liste-CSV bruger BEGGE de filtre tallene paa skaermen er regnet med', function () {
    // Et felt kan aendres uden at der regnes igen (fx et `.change`-felt der
    // synces i samme request som klikket). Komponenten bruges derfor direkte,
    // saa feltet kan aendres UDEN hooks, og ingen af filerne maa tage det med.
    Http::fake([
        '*/company-segmentation/companies/export-link' => Http::response(['url' => 'https://registry.test/liste.csv?signature=a']),
        '*/company-segmentation/export-link' => Http::response(['url' => 'https://registry.test/taelling.csv?signature=b']),
        '*/company-segmentation' => Http::response(['data' => [['key' => '751', 'label' => null, 'count' => 6924]], 'meta' => ['total' => 117382]]),
    ]);

    $c = new CompanySegmentation;
    $c->segmentér();
    $c->ivaerksaetter = true;
    $c->groupBy = 'region';

    $c->hentCsv();
    $c->hentUdvalgCsv();

    $links = collect(Http::recorded())->map(fn ($par) => $par[0])
        ->filter(fn ($r) => str_contains($r->url(), 'export-link'));

    expect($links)->toHaveCount(2);
    foreach ($links as $r) {
        expect($r['group_by'])->toBe('municipality_code')
            ->and($r->data())->not->toHaveKey('ivaerksaetter');
    }
});
