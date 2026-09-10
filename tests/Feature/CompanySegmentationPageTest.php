<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;
use TheFountainhead\Metis\Services\QuotaExceededException;

uses(RefreshDatabase::class);

/**
 * Selskabssegmentering — taellinger fordelt paa kommune, branche, selskabsform.
 *
 * 🚨 Kernen i disse tests er ikke at tallet er rigtigt, men at siden ikke kan
 * VILDLEDE: totalen skal altid vises ved siden af den afkortede gruppeliste,
 * og raa koder maa aldrig staa alene som eneste etiket.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    Http::preventStrayRequests();
});

function fakeSegmentering(array $grupper, int $total): void
{
    Http::fake([
        '*/company-segmentation' => Http::response(['data' => $grupper, 'meta' => ['total' => $total]]),
    ]);
}

it('viser totalen ved siden af gruppelisten', function () {
    // 🔑 Grupperne summer til 31.850, men populationen er 152.556. Vises kun
    // grupperne, laeses en afkortet top-liste som facit.
    fakeSegmentering([
        ['key' => '101', 'label' => null, 'count' => 22724],
        ['key' => '751', 'label' => null, 'count' => 9126],
    ], 152556);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('152.556')
        ->assertSee('22.724');
});

it('oversaetter kommunekoder til navne — en kode alene er ubrugelig', function () {
    fakeSegmentering([['key' => '101', 'label' => null, 'count' => 22724]], 22724);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('København');
});

it('oversaetter selskabsformer til navne', function () {
    fakeSegmentering([['key' => 'aps', 'label' => null, 'count' => 134525]], 134525);

    Livewire::test(CompanySegmentation::class)
        ->set('groupBy', 'company_type')
        ->call('segmentér')
        ->assertSee('Anpartsselskab');
});

it('foretraekker API-ets egen label naar den findes', function () {
    // 🪤 Branchekoder HAR label fra API'et. Overskriver vi den med vores egen
    // opslagstabel, ville vi vise noget andet end registret selv siger.
    fakeSegmentering([
        ['key' => '62.01.00', 'label' => 'Computerprogrammering', 'count' => 5000],
    ], 5000);

    Livewire::test(CompanySegmentation::class)
        ->set('groupBy', 'industry_code')
        ->call('segmentér')
        ->assertSee('Computerprogrammering');
});

it('sender ivaerksaetter-filteret med til API-et', function () {
    fakeSegmentering([], 0);

    Livewire::test(CompanySegmentation::class)
        ->set('ivaerksaetter', true)
        ->call('segmentér');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'company-segmentation')
            && ($request->data()['ivaerksaetter'] ?? null) === true;
    });
});

it('udelader tomme filtre helt — en tom streng er ikke et filter', function () {
    fakeSegmentering([], 0);

    $c = Livewire::test(CompanySegmentation::class)
        ->set('municipalityCode', '')
        ->set('industryPrefix', '')
        ->call('segmentér');

    expect($c->instance()->filtre())->not->toHaveKey('municipality_code')
        ->and($c->instance()->filtre())->not->toHaveKey('industry_prefix');
});

it('siger det tydeligt naar ingen selskaber matcher', function () {
    fakeSegmentering([], 0);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('Ingen selskaber matcher');
});

it('viser en fejlbesked i stedet for et tomt resultat naar API-et svigter', function () {
    // 🚨 Et tomt resultat og en fejlet forespoergsel ser ens ud for brugeren,
    // hvis fejlen ikke siges. "0 selskaber" ville vaere en forkert paastand.
    Http::fake(['*' => Http::response([], 500)]);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('kunne ikke hentes')
        ->assertDontSee('Ingen selskaber matcher');
});

it('henter et signeret link naar der klikkes paa Excel-udtraek', function () {
    // 🚨 Linket maa ALDRIG bygges i klienten: CSV-ruten er auth-fri og
    // beskyttet af signaturen alene, og API-noeglen maa ikke i HTML'en.
    Http::fake([
        '*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        '*/export-link' => Http::response(['url' => 'https://registry.test/v1/company-segmentation.csv?signature=abc']),
    ]);

    Livewire::test(CompanySegmentation::class)
        ->call('hentCsv')
        ->assertRedirect('https://registry.test/v1/company-segmentation.csv?signature=abc');
});

it('siger fra naar udtraekket ikke kan dannes', function () {
    Http::fake([
        '*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        '*/export-link' => Http::response([], 403),
    ]);

    Livewire::test(CompanySegmentation::class)
        ->call('hentCsv')
        ->assertSee('kunne ikke dannes');
});

it('🚨 viser en besked i stedet for at crashe naar kvoten er opbrugt', function () {
    // `client()` kaster FOER HTTP-kaldet, og `mount()` kalder segmentér() ved
    // hver page load. Uden en catch her ville siden give 500 for enhver
    // besoegende med opbrugt kvote.
    config()->set('metis.gating.enabled', true);
    config()->set('metis.gating.free_lookups', 0);
    Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    $this->mock(\TheFountainhead\Metis\Services\RegistryApi::class, function ($m) {
        $m->shouldReceive('segmentCompanies')->andThrow(new QuotaExceededException);
    });

    Livewire::test(CompanySegmentation::class)
        ->assertOk()
        ->assertSee('gratis opslag');
});

it('🚨 kalder et 422 for en AFVIST forespoergsel — ikke "ingen selskaber"', function () {
    // postEnvelope() returnerer 422-kroppen RAAT uden `error`-noegle. Falder
    // den igennem til succes-grenen, faar brugeren at vide at populationen er
    // tom, hvor sandheden er at inputtet blev afvist.
    Http::fake([
        '*/company-segmentation' => Http::response([
            'message' => 'Ugyldig stiftelsesdato.',
            'errors' => ['founded_from' => ['Ugyldig dato']],
        ], 422),
    ]);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('Ugyldig stiftelsesdato')
        ->assertDontSee('Ingen selskaber matcher');
});

it('🚨 afviser et svar UDEN meta.total — 0 over en fyldt tabel er selvmodsigende', function () {
    Http::fake([
        '*/company-segmentation' => Http::response([
            'data' => [['key' => '101', 'label' => null, 'count' => 22724]],
        ]),
    ]);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('kunne ikke hentes')
        ->assertDontSee('22.724');
});

it('🪤 falder tilbage til standard-gruppering ved ugyldig ?grupper i URL-en', function () {
    fakeSegmentering([], 0);

    $c = Livewire::test(CompanySegmentation::class)
        ->set('groupBy', 'noget-opdigtet')
        ->call('segmentér');

    expect($c->instance()->groupBy)->toBe('municipality_code');
});

it('eksporterer ikke foer der ER et resultat paa skaermen', function () {
    Http::fake(['*/company-segmentation' => Http::response([], 500)]);

    Livewire::test(CompanySegmentation::class)
        ->call('hentCsv')
        ->assertSee('Hent et resultat frem');
});

it('🪤 eksporterer med SAMME gruppe-loft som visningen', function () {
    Http::fake([
        '*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 5]]),
        '*/export-link' => Http::response(['url' => 'https://registry.test/csv?signature=abc']),
    ]);

    Livewire::test(CompanySegmentation::class)->call('hentCsv');

    Http::assertSent(fn ($r) => ! str_contains($r->url(), 'export-link')
        || ($r->data()['limit'] ?? null) === CompanySegmentation::GRUPPE_LOFT);
});

/**
 * 🚨 RUTE-SMOKETEST. Alle de øvrige tests bruger `Livewire::test()`, som
 * renderer komponenten UDEN layout og derfor aldrig kan blive rød af et
 * manglende layout-kald. Målt: siden gav 500 i standalone, mens 16 grønne
 * komponent-tests sagde god for den. Se leverancen i FORBRUGSLAGET.
 */
it('🚨 svarer 200 paa selve ruten — ikke kun i komponent-testen', function () {
    config()->set('metis.mode', 'standalone');
    fakeSegmentering([], 0);

    $this->get('/segmentering')->assertOk();
});

it('🪤 crasher ikke paa et array i query-strengen', function () {
    // ?kommune[]=a&kommune[]=b gav TypeError paa en typed property = 500,
    // før en linje af vores egen logik nåede at køre.
    fakeSegmentering([], 0);

    Livewire::test(CompanySegmentation::class, ['municipalityCode' => ['a', 'b']])
        ->assertOk();
});

it('🪤 crasher ikke paa et malformet 200-svar hvor data ikke er en liste', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => 'ups', 'meta' => ['total' => 3]])]);

    Livewire::test(CompanySegmentation::class)->assertOk();
});

it('🚨 redirecter ALDRIG til en fremmed vaert', function () {
    // Kompromitteres registry-api'et, maa et svar ikke kunne sende brugeren
    // hvor som helst hen.
    Http::fake([
        '*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 5]]),
        '*/export-link' => Http::response(['url' => 'https://ondsindet.example.com/pwn']),
    ]);

    Livewire::test(CompanySegmentation::class)
        ->call('hentCsv')
        ->assertNoRedirect()
        ->assertSee('kunne ikke dannes');
});

it('🪤 laegger ALDRIG to w-full felter i samme flex-raekke', function () {
    // `w-full` er 100 % af foraelderen. To af dem side om side kraever 200 %
    // plus gap. Flex ville normalt krympe dem, men `type="date"` har en
    // iboende min-bredde fra datovaelgeren og kan ikke krympe under den — saa
    // det hoejre felt flyder ud af rammen. Maalt i Word-eksporten 10/9.
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/company-segmentation.blade.php');

    preg_match_all('/<div class="flex gap-\d+[^"]*">(.*?)<\/div>/s', $blade, $m);

    foreach ($m[1] as $raekke) {
        $antal = substr_count($raekke, 'class="w-full');
        expect($antal)->toBeLessThan(2,
            'To eller flere w-full felter i samme flex-raekke flyder ud af rammen. Brug flex-1 min-w-0.');
    }
})->group('layout');
