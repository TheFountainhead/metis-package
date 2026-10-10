<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TheFountainhead\Metis\Livewire\Search;
use TheFountainhead\Metis\Models\MetisLookup;
use TheFountainhead\Metis\Http\Controllers\MetisPdfController;
use TheFountainhead\Metis\Services\RegistryApi;
use TheFountainhead\Metis\View\Components\MetisLink;

/**
 * CPR-opslag er lukket i Metis — Frederiks beslutning 9/10-2026.
 *
 * `/lookup/cpr/<personnummer>` lagde personnummeret i URL'en (nginx-,
 * Cloudflare- og Flare-logs, browserhistorik, Googles crawl-lister) og hentede
 * navn, adresse og bopael fra CPR Direkte bag kun navn + arbejdsmail. Ruten
 * havde 0 kald paa 15 dage (nginx 25/9-9/10; 86 kald til de andre typer).
 *
 * 🔑 Lukket i DATALAGET, ikke kun paa siden: sektionerne kan kaldes direkte
 * over `/livewire/update` (lektionen fra #185). Derfor koerer testene med
 * gating SLAAET FRA, hvor alle tidligere var "identificerede" — lukningen maa
 * ikke afhaenge af login-gaten.
 */
uses(RefreshDatabase::class);

const LUKKET_CPR = '0101011234';

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', false);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => ['person_name' => 'HEMMELIG PERSON']])]);
});

it('sender /lookup/cpr/ til forsiden uden personnummeret i adressen', function (string $cpr) {
    $response = $this->get('/lookup/cpr/'.$cpr);

    $response->assertRedirect(route('metis.home'));
    expect($response->headers->get('Location'))->not->toContain('0101011234')
        ->and($response->headers->get('Location'))->not->toContain('010101-1234');
    Http::assertNothingSent();
})->with(['uden bindestreg' => LUKKET_CPR, 'med bindestreg' => '010101-1234']);

it('sender /lookup/cpr/ til forsiden ogsaa naar vaerdien ikke er et CPR', function () {
    $this->get('/lookup/cpr/Lars')->assertRedirect(route('metis.home'));
    expect(MetisLookup::count())->toBe(0);
    Http::assertNothingSent();
});

it('genkender CPR skrevet med andre skilletegn og sender det til forsiden', function (string $cpr) {
    $response = $this->get('/lookup/cvr/'.rawurlencode($cpr));

    $response->assertRedirect(route('metis.home'));
    expect(MetisLookup::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'NBSP' => "010101\u{00A0}1234",
    'tankestreg' => "010101\u{2013}1234",
    'ikke-brydende bindestreg' => "010101\u{2011}1234",
    'punktum' => '010101.1234',
    'skraastreg' => '010101/1234',
]);

it('blokerer et CPR i krydsopslaget, uanset den type klienten sender', function (string $type, string $value) {
    Livewire::test(Search::class)
        ->call('crossReference', $type, $value)
        ->assertSet('cprBlocked', true)
        ->assertNotDispatched('update-url');

    expect(MetisLookup::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'cpr' => ['cpr', LUKKET_CPR],
    'CPR (store bogstaver)' => ['CPR', LUKKET_CPR],
    'CPR forklaedt som cvr' => ['cvr', LUKKET_CPR],
    'CPR forklaedt som adresse' => ['address', '010101-1234'],
]);

it('svarer 404 paa en CPR-PDF, ogsaa forklaedt som cvr', function (string $type) {
    expect(fn () => app(MetisPdfController::class)->download($type, LUKKET_CPR))
        ->toThrow(NotFoundHttpException::class);
    Http::assertNothingSent();
})->with(['cpr', 'CPR', 'cvr']);

it('sender /lookup/CPR/ (store bogstaver i typen) til forsiden', function () {
    $this->get('/lookup/CPR/'.LUKKET_CPR)->assertRedirect(route('metis.home'));
    Http::assertNothingSent();
});

it('sender et CPR indtastet under en anden type til forsiden, ikke til /lookup/cpr/', function (string $type) {
    $response = $this->get("/lookup/{$type}/010101-1234");

    $response->assertRedirect(route('metis.home'));
    expect($response->headers->get('Location'))->not->toContain('/lookup/cpr');
    Http::assertNothingSent();
})->with(['person', 'address', 'cvr', 'name']);

it('bygger aldrig en cpr-URL', function () {
    expect(MetisLink::urlFor('cpr', LUKKET_CPR))->toBeNull()
        ->and(MetisLink::urlFor('CPR', LUKKET_CPR))->toBeNull()
        ->and(MetisLink::urlForEllerHjem('cpr', LUKKET_CPR))->toBe(route('metis.home'));
});

it('lukker alle CPR-metoder i datalaget uden et HTTP-kald', function (string $metode) {
    $svar = app(RegistryApi::class)->{$metode}(LUKKET_CPR);

    expect($svar)->toMatchArray(['error' => 'cpr_disabled', 'status' => 403]);
    Http::assertNothingSent();
})->with([
    'fetchCompaniesByCpr',
    'fetchCompaniesByCprCached',
    'fetchPropertiesByCpr',
    'fetchPersonPropertyPortfolioByCpr',
    'fetchPersonPropertyPortfolioByCprCached',
]);

it('udleverer ikke et cachet CPR-svar fra foer lukningen', function () {
    Cache::put('metis:companies_by_cpr:'.sha1(LUKKET_CPR), ['companies' => [['name' => 'HEMMELIG ApS']]], 300);
    Cache::put('metis:person_property_portfolio:'.sha1(LUKKET_CPR), ['personal_properties' => [['bfe' => 1]]], 300);

    $api = app(RegistryApi::class);

    expect($api->fetchCompaniesByCprCached(LUKKET_CPR))->toMatchArray(['error' => 'cpr_disabled'])
        ->and($api->fetchPersonPropertyPortfolioByCprCached(LUKKET_CPR))->toMatchArray(['error' => 'cpr_disabled'])
        ->and($api->fetchPersonPropertyPortfolioByCprFromCache(LUKKET_CPR))->toBeNull();
});

it('samler ingen CPR-data til en PDF', function () {
    expect(app(MetisPdfController::class)->gatherData('cpr', LUKKET_CPR))->toBe([]);
    Http::assertNothingSent();
});
