<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\MapPanel;

/**
 * Fri udforskning af kortet: ejendomme i det udsnit man kigger på (kun piloter).
 *
 * 🚨 `kanUdforske` er en offentlig Livewire-egenskab, så klienten kan sætte den selv. Gaten
 * ligger derfor i hentUdsnit() og tjekkes hver gang.
 */
function udsnitRaekke(int $nr): array
{
    return [
        'matrikel_id' => "m$nr", 'address' => "Vej $nr", 'postal_code' => '2000', 'city' => 'Frederiksberg',
        'year_built' => 1950, 'area_building' => 100, 'valuation' => 2000000,
        'total_debt' => 1, 'weighted_rate' => 2.0,
        'latitude' => 55.676 + $nr / 100000, 'longitude' => 12.568,
    ];
}

function udsnitSide(array $raekker, ?string $cursor = null, bool $flere = false): \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['data' => ['results' => $raekker, 'cursor' => $cursor, 'has_more' => $flere]]);
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', false); // gating fra = harPilotAdgang() er sand
    Cache::flush();
    RateLimiter::clear('metis-udsnit:127.0.0.1');
    Http::preventStrayRequests();
});

function udsnit(float $syd = 55.6750, float $vest = 12.5650, float $nord = 55.6790, float $oest = 12.5720): array
{
    return (new MapPanel)->hentUdsnit($syd, $vest, $nord, $oest);
}

it('🚨 afviser alle, der ikke er piloter, også når klienten selv sætter kanUdforske', function () {
    config()->set('metis.gating.enabled', true);
    Http::fake(['*' => Http::response(['data' => []])]);

    $panel = new MapPanel;
    $panel->kanUdforske = true;

    expect($panel->hentUdsnit(55.6750, 12.5650, 55.6790, 12.5720))->toBe(['error' => 'kraever_pilot']);
    Http::assertNothingSent();
});

it('henter ejendomme for udsnittet og sender kun hvidlistede felter', function () {
    Http::fake(['*/v1/property-explore' => udsnitSide([udsnitRaekke(1), udsnitRaekke(2)])]);

    $svar = udsnit();

    expect($svar['naboer'])->toHaveCount(2)
        ->and($svar['flere'])->toBeFalse()
        ->and(array_keys($svar['naboer'][0]))->toBe(['adresse', 'postnr', 'by', 'byggeaar', 'areal', 'vurdering', 'url', 'lat', 'lng'])
        ->and(json_encode($svar))->not->toContain('total_debt')->not->toContain('weighted_rate');

    Http::assertSent(fn ($r) => $r['limit'] === 50 && ! isset($r['cursor']) && count($r['polygon']) === 4
        && $r->header('X-Search-Source')[0] === 'map_viewport');
});

it('snapper udsnittet til et gitter, så små panoreringer rammer cachen', function () {
    Http::fake(['*/v1/property-explore' => udsnitSide([udsnitRaekke(1)])]);

    udsnit(55.6752, 12.5652, 55.6788, 12.5718);
    udsnit(55.6754, 12.5656, 55.6786, 12.5714); // samme gitterceller, lidt panoreret

    Http::assertSentCount(1);
    Http::assertSent(function ($r) {
        $lat = array_column($r['polygon'], 'lat');
        $lng = array_column($r['polygon'], 'lng');

        // Gitter 0,002: syd/vest rundes ned, nord/øst op, så udsnittet altid dækker det viste.
        return abs(min($lat) - 55.674) < 1e-9 && abs(max($lat) - 55.680) < 1e-9
            && abs(min($lng) - 12.564) < 1e-9 && abs(max($lng) - 12.572) < 1e-9;
    });
});

it('en værdi præcis på en gitterlinje giver samme udsnit som en lidt større', function () {
    Http::fake(['*/v1/property-explore' => udsnitSide([udsnitRaekke(1)])]);

    // 55.678 / 0.002 giver 27838,999999999996 i flydende tal: uden afrundingen ville syd falde i
    // cellen under (55.676) og give en anden cache-nøgle end et udsnit lidt inde i samme celle.
    udsnit(55.678, 12.570, 55.682, 12.576);
    udsnit(55.6781, 12.5701, 55.6819, 12.5759);

    Http::assertSentCount(1);
});

it('afviser ugyldige og for store udsnit uden at kalde registry-api', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    expect(udsnit(55.0, 12.0, 54.0, 13.0))->toBe(['error' => 'ugyldigt'])   // omvendt
        ->and(udsnit(10.0, 12.0, 10.01, 12.01))->toBe(['error' => 'ugyldigt'])  // uden for Danmark
        ->and(udsnit(55.0, 12.0, NAN, 12.1))->toBe(['error' => 'ugyldigt'])
        ->and(udsnit(55.60, 12.50, 55.70, 12.52))->toBe(['error' => 'zoom_ind']) // 0,1 grader nord-syd
        ->and(udsnit(55.60, 12.30, 55.61, 12.40))->toBe(['error' => 'zoom_ind']); // 0,1 grader øst-vest

    Http::assertNothingSent();
});

it('henter op til tre sider med cursor og siger til, når der er flere', function () {
    Http::fake(['*/v1/property-explore' => Http::sequence()
        ->push(['data' => ['results' => [udsnitRaekke(1)], 'cursor' => 'c1', 'has_more' => true]])
        ->push(['data' => ['results' => [udsnitRaekke(2)], 'cursor' => 'c2', 'has_more' => true]])
        ->push(['data' => ['results' => [udsnitRaekke(3)], 'cursor' => 'c3', 'has_more' => true]])
        ->push(['data' => ['results' => [udsnitRaekke(4)], 'cursor' => null, 'has_more' => false]]),
    ]);

    $svar = udsnit();

    expect($svar['naboer'])->toHaveCount(3)->and($svar['flere'])->toBeTrue();
    Http::assertSentCount(3);
    Http::assertSent(fn ($r) => ($r['cursor'] ?? null) === 'c2');
});

it('stopper efter sidste side, når der ikke er flere', function () {
    Http::fake(['*/v1/property-explore' => Http::sequence()
        ->push(['data' => ['results' => [udsnitRaekke(1)], 'cursor' => 'c1', 'has_more' => true]])
        ->push(['data' => ['results' => [udsnitRaekke(2)], 'cursor' => null, 'has_more' => false]]),
    ]);

    $svar = udsnit();

    expect($svar['naboer'])->toHaveCount(2)->and($svar['flere'])->toBeFalse();
    Http::assertSentCount(2);
});

it('en fejl på side to giver fejl for hele udsnittet og cacher intet', function () {
    Http::fake(['*/v1/property-explore' => Http::sequence()
        ->push(['data' => ['results' => [udsnitRaekke(1)], 'cursor' => 'c1', 'has_more' => true]])
        ->push([], 500)
        ->push(['data' => ['results' => [udsnitRaekke(1)], 'cursor' => null, 'has_more' => false]]),
    ]);

    expect(udsnit())->toBe(['error' => 'fejl'])
        ->and(udsnit())->toHaveKey('naboer'); // ikke cachet fejl: næste forsøg henter igen
});

it('oversætter 429 til for_mange', function () {
    Http::fake(['*/v1/property-explore' => Http::response(['message' => 'x'], 429)]);

    expect(udsnit())->toBe(['error' => 'for_mange']);
});

it('begrænser til 15 udsnit i minuttet', function () {
    Http::fake(['*/v1/property-explore' => udsnitSide([udsnitRaekke(1)])]);

    for ($i = 0; $i < 15; $i++) {
        expect(udsnit(55.60 + $i * 0.01, 12.50, 55.604 + $i * 0.01, 12.51))->toHaveKey('naboer');
    }

    expect(udsnit(55.90, 12.50, 55.904, 12.51))->toBe(['error' => 'for_mange']);
});

it('kortets kontrol til fri udforskning er med for piloter og kan parses som JavaScript', function () {
    if (! trim((string) shell_exec('command -v node'))) {
        $this->markTestSkipped('node findes ikke');
    }
    Http::fake(['*' => Http::response(['data' => []])]);

    $html = Livewire::test(MapPanel::class, ['lat' => 55.6761, 'lng' => 12.5683, 'searchType' => 'address', 'query' => 'Roskildevej 10, 2000 Frederiksberg'])->html();

    preg_match('/x-data="(.*?)"\s+x-ref=/s', $html, $m);
    $js = html_entity_decode($m[1], ENT_QUOTES);
    $fil = tempnam(sys_get_temp_dir(), 'xdata').'.js';
    file_put_contents($fil, '('.$js.')');
    exec('node --check '.escapeshellarg($fil).' 2>&1', $ud, $kode);
    @unlink($fil);

    expect($kode)->toBe(0, implode("\n", $ud))
        ->and($js)->toContain('kanUdforske: true')->toContain('Udforsk kortet frit')->toContain('hentUdsnit')
        ->toContain('minZoom: '.MapPanel::UDSNIT_MIN_ZOOM);
});
