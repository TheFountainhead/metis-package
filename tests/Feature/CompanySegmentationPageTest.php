<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;

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
