<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Segmenteringen kraever en bekraeftet pilot (licensbruger).
 *
 * Maalt 11/10-2026: /segmentering gav anonyme ubegraenset adgang til tal og
 * Excel-udtraek. En segmentering taeller ikke som et opslag og brugte derfor
 * aldrig af den anonyme kvote. Funktionen blev bygget til og tilbudt
 * Erhvervsstyrelsen som licens til navngivne brugere.
 *
 * 🚨 Gaten skal holde over `/livewire/update`, ikke kun paa siden: begge
 * metoder der kalder API'et er offentlige (lektionen fra #185).
 */
const SEG_HEMMELIG_TOTAL = 987654;

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    config()->set('metis.gating.enabled', true);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*/company-segmentation/export-link' => Http::response(['url' => 'https://registry.test/v1/company-segmentation.csv?signature=x']),
        '*/company-segmentation' => Http::response([
            'data' => [['key' => '101', 'label' => null, 'count' => 12345]],
            'meta' => ['total' => SEG_HEMMELIG_TOTAL],
        ]),
    ]);
});

function erSegmentering($r): bool
{
    return str_contains($r->url(), '/company-segmentation');
}

function segmenteringSnapshot(string $html): string
{
    preg_match('/wire:snapshot="([^"]*company-segmentation[^"]*)"/', $html, $m);

    expect($m)->toHaveKey(1);

    return html_entity_decode($m[1], ENT_QUOTES);
}

function segmenteringKald(object $test, string $snapshot, string $metode)
{
    return $test->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => $metode, 'params' => []]],
        ]],
    ]);
}

dataset('segmentering-ikke-piloter', [
    'anonym' => [[]],
    'kun verificeret mail' => [['metis_verified_email' => 'kunde@firma.dk']],
    'uproevet token' => [['metis_user_token' => '1|x']],
    'verificeret mail + uproevet token' => [['metis_verified_email' => 'kunde@firma.dk', 'metis_user_token' => '1|x']],
]);

it('🚨 en ikke-pilot ser login-beskeden og ingen tal paa siden', function (array $session) {
    if ($session !== []) {
        $this->withSession($session);
    }

    $this->get('/segmentering')->assertOk()
        ->assertSee('Kun for brugere med konto')
        ->assertDontSee('987.654')
        ->assertDontSee('Hent til Excel');

    Http::assertNotSent(fn ($r) => erSegmentering($r));
})->with('segmentering-ikke-piloter');

it('🚨 en ikke-pilot faar INTET over /livewire/update, heller ikke et udtraek', function (array $session) {
    if ($session !== []) {
        $this->withSession($session);
    }

    $snap = segmenteringSnapshot($this->get('/segmentering')->getContent());

    foreach (['segmentér', 'hentCsv'] as $metode) {
        segmenteringKald($this, $snap, $metode)->assertOk()
            ->assertDontSee('987.654')
            ->assertDontSee('signature=');
    }

    Http::assertNotSent(fn ($r) => erSegmentering($r));
})->with('segmentering-ikke-piloter');

it('🚨 datalaget afviser en ikke-pilot uden et HTTP-kald', function () {
    $this->withSession(['metis_verified_email' => 'kunde@firma.dk', 'metis_user_token' => '1|x']);
    $api = app(RegistryApi::class);

    expect($api->segmentCompanies('municipality_code'))->toBe(['error' => 'pilot_required', 'status' => 403])
        ->and($api->segmentationExportLink('municipality_code'))->toBeNull();

    Http::assertNothingSent();
});

it('modstykke: en bekraeftet pilot ser tallene og kan hente udtraekket', function () {
    $this->withSession(['metis_user_token' => '1|aegte', 'metis_pilot_account_id' => 7, 'metis_verified_email' => 'pilot@frankston.io']);

    $html = $this->get('/segmentering')->assertOk()
        ->assertDontSee('Kun for brugere med konto')
        ->assertSee('987.654')
        ->getContent();

    segmenteringKald($this, segmenteringSnapshot($html), 'hentCsv')->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/company-segmentation/export-link'));
});

it('modstykke: med gaten slaaet fra (embedded bag vaertens login) virker siden', function () {
    config()->set('metis.gating.enabled', false);

    Livewire::test(CompanySegmentation::class)
        ->assertSet('total', SEG_HEMMELIG_TOTAL)
        ->assertDontSee('Kun for brugere med konto');
});
