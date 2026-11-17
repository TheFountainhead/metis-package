<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\MapPanel;
use TheFountainhead\Metis\Services\LookupAccess;

/**
 * Naboejendomme på kortet.
 *
 * 🚨 Gaten ligger i hentNaboer(), ikke i mount(): `lat`/`lng` er offentlige Livewire-
 * egenskaber, så klienten kan sætte dem selv. Metoden skal derfor finde koordinaterne
 * via den godkendte adresse og aldrig bruge `$this->lat`.
 */
const ADRESSE = 'Roskildevej 10, 2000 Frederiksberg';

function naboSvar(array $raekker, bool $flere = false): array
{
    return ['data' => ['results' => $raekker, 'cursor' => null, 'has_more' => $flere]];
}

function naboRaekke(array $over = []): array
{
    return $over + [
        'matrikel_id' => '1a',
        'address' => 'Roskildevej 12',
        'postal_code' => '2000',
        'city' => 'Frederiksberg',
        'year_built' => 1932,
        'area_building' => 180,
        'valuation' => 4500000,
        'total_debt' => 3000000,
        'weighted_rate' => 3.4,
        'latitude' => 55.6770,
        'longitude' => 12.5690,
    ];
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', false);
    RateLimiter::clear('metis-naboer:127.0.0.1');
    Cache::flush();
    Http::preventStrayRequests();
});

function fakeNaboer(array $explore): void
{
    Http::fake([
        '*/v1/map/layers' => Http::response(['data' => []]),
        '*/v1/property-explore' => $explore['svar'] ?? Http::response(naboSvar([])),
        '*/v1/property/analysis' => Http::response(['data' => ['property' => ['coordinates' => ['lat' => 55.6761, 'lng' => 12.5683]]]]),
        '*' => Http::response(['data' => []]),
    ]);
}

it('henter ejendomme omkring adressen og sender kun hvidlistede felter ud', function () {
    fakeNaboer(['svar' => Http::response(naboSvar([
        naboRaekke(),
        naboRaekke(['address' => 'Selve ejendommen', 'latitude' => 55.6761, 'longitude' => 12.5683]),
        naboRaekke(['address' => 'Uden koordinater', 'latitude' => null, 'longitude' => null]),
    ]))]);

    $svar = (new MapPanel)->hentNaboer(ADRESSE, 250);

    expect($svar['naboer'])->toHaveCount(1)
        ->and($svar['naboer'][0])->toEqual([
            'adresse' => 'Roskildevej 12', 'postnr' => '2000', 'by' => 'Frederiksberg',
            'byggeaar' => 1932, 'areal' => 180, 'vurdering' => 4500000,
            'url' => 'http://localhost/lookup/address/Roskildevej%2012%2C%202000%20Frederiksberg',
            'lat' => 55.677, 'lng' => 12.569,
        ])
        ->and($svar['radius'])->toBe(250)
        ->and($svar['flere'])->toBeFalse();

    // Gæld må aldrig forlade serveren, og selve ejendommen har allerede sin markør.
    expect(json_encode($svar))->not->toContain('total_debt')->not->toContain('weighted_rate')
        ->not->toContain('Selve ejendommen')->not->toContain('Uden koordinater');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/property-explore')
        && $r['lat'] === 55.6761 && $r['lng'] === 12.5683 && $r['radius'] === 250 && $r['limit'] === 50
        && $r->header('X-Search-Source')[0] === 'map_neighbours');
});

it('falder tilbage til 250 m ved en radius uden for listen', function () {
    fakeNaboer([]);

    expect((new MapPanel)->hentNaboer(ADRESSE, 99999)['radius'])->toBe(250)
        ->and((new MapPanel)->hentNaboer(ADRESSE, 100)['radius'])->toBe(100);

    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v1/property-explore') && $r['radius'] === 99999);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/property-explore') && $r['radius'] === 250);
});

it('🚨 bruger ikke klientens lat/lng, men adressens koordinater', function () {
    fakeNaboer([]);

    $panel = new MapPanel;
    $panel->lat = 10.0;
    $panel->lng = 10.0;
    $panel->hentNaboer(ADRESSE, 250);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/property-explore') && $r['lat'] === 55.6761);
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v1/property-explore') && $r['lat'] === 10.0);
});

it('🚨 henter intet for en adresse sessionen ikke har fået godkendt', function () {
    config()->set('metis.gating.enabled', true);
    fakeNaboer([]);

    $svar = (new MapPanel)->hentNaboer(ADRESSE, 250);

    expect($svar)->toBe(['error' => 'ikke_godkendt']);
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v1/property-explore'));
});

it('henter, når sessionen har godkendt netop den adresse', function () {
    config()->set('metis.gating.enabled', true);
    config()->set('metis.gating.free_lookups', 5);
    fakeNaboer(['svar' => Http::response(naboSvar([naboRaekke()]))]);
    $this->withSession(['metis_session_started' => true]);
    app(LookupAccess::class)->godkendOpslag('address', ADRESSE);

    $svar = (new MapPanel)->hentNaboer(ADRESSE, 250);

    expect($svar)->toHaveKey('naboer');
});

it('melder flere, når der er mere end de viste', function () {
    fakeNaboer(['svar' => Http::response(naboSvar([naboRaekke()], flere: true))]);

    expect((new MapPanel)->hentNaboer(ADRESSE, 500)['flere'])->toBeTrue();
});

it('oversætter 429 fra registry-api til kvote', function () {
    fakeNaboer(['svar' => Http::response(['message' => 'Too Many'], 429)]);

    expect((new MapPanel)->hentNaboer(ADRESSE, 250))->toBe(['error' => 'kvote']);
});

it('oversætter andre fejl fra registry-api til fejl', function () {
    fakeNaboer(['svar' => Http::response([], 500)]);

    expect((new MapPanel)->hentNaboer(ADRESSE, 250))->toBe(['error' => 'fejl']);
});

it('begrænser til 20 opslag i minuttet', function () {
    fakeNaboer([]);

    for ($i = 0; $i < 20; $i++) {
        expect((new MapPanel)->hentNaboer(ADRESSE, 250))->toHaveKey('naboer');
    }

    expect((new MapPanel)->hentNaboer(ADRESSE, 250))->toBe(['error' => 'for_mange']);
});

it('🚨 kortets x-data kan parses som JavaScript med naboejendomme', function () {
    if (! trim((string) shell_exec('command -v node'))) {
        $this->markTestSkipped('node findes ikke');
    }
    fakeNaboer([]);

    $html = Livewire::test(MapPanel::class, ['lat' => 55.6761, 'lng' => 12.5683, 'searchType' => 'address', 'query' => ADRESSE])->html();

    preg_match('/x-data="(.*?)"\s+x-ref=/s', $html, $m);
    expect($m)->toHaveCount(2);

    $js = html_entity_decode($m[1], ENT_QUOTES);
    $fil = tempnam(sys_get_temp_dir(), 'xdata').'.js';
    file_put_contents($fil, '('.$js.')');
    exec('node --check '.escapeshellarg($fil).' 2>&1', $ud, $kode);
    @unlink($fil);

    expect($kode)->toBe(0, implode("\n", $ud))
        ->and($js)->toContain('hentNaboer')->toContain('n.url')->not->toContain('route(');
});
