<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TheFountainhead\Metis\Livewire\Search;
use TheFountainhead\Metis\Livewire\Sections\PersonStructure;
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

it('har ingen CPR-metoder i datalaget', function () {
    // Foer FHT-2348 stod seks CPR-metoder tilbage som stubs, der svarede
    // cpr_disabled. De er fjernet: "lukket" er nu "findes ikke". Skulle en
    // CPR-metode komme tilbage (eller en ny blive tilfoejet), fejler testen.
    $metoder = collect((new ReflectionClass(RegistryApi::class))->getMethods())
        ->map(fn (ReflectionMethod $m) => $m->getName());

    expect($metoder)->toContain('fetchCompaniesByName') // positiv kontrol: refleksionen virker
        ->and($metoder->filter(fn (string $navn) => stripos($navn, 'cpr') !== false)->values()->all())->toBe([]);
});

it('har ingen CPR-sektioner: de kan hverken mountes eller laver HTTP', function (string $komponent) {
    expect(fn () => Livewire::test($komponent, ['query' => LUKKET_CPR]))
        ->toThrow(\Livewire\Exceptions\ComponentNotFoundException::class);
    Http::assertNothingSent();
})->with([
    'metis-person-summary',
    'metis-person-info',
    'metis-person-companies',
    'metis-person-properties',
    'metis-person-relations',
]);

it('sender ikke et CPR til navneopslaget, heller ikke med en varm CPR-cache fra foer lukningen', function (string $cpr) {
    Cache::put('metis:companies_by_cpr:'.sha1(LUKKET_CPR), ['companies' => [['cvr' => '11111111', 'name' => 'HEMMELIG ApS', 'is_active' => true, 'has_direct_ownership' => true]]], 300);
    Cache::put('metis:person_property_portfolio:'.sha1(LUKKET_CPR), ['personal_properties' => [['address' => 'HEMMELIG VEJ 1']]], 300);

    $test = Livewire::test(PersonStructure::class, ['query' => $cpr])
        ->call('tick')
        ->call('retrySkeleton');

    expect($test->get('skeletonStatus'))->toBe('failed')
        ->and(json_encode($test->instance()->all()))->not->toContain('HEMMELIG')
        ->and($test->html())->not->toContain('HEMMELIG');
    Http::assertNothingSent();
})->with(['uden bindestreg' => LUKKET_CPR, 'med bindestreg' => '010101-1234']);

it('modstykke: navneopslaget sender stadig et rigtigt navn', function () {
    Livewire::test(PersonStructure::class, ['query' => 'Lars Larsen']);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/cvr/person-companies-by-name'));
});

it('samler ingen CPR-data til en PDF', function () {
    expect(app(MetisPdfController::class)->gatherData('cpr', LUKKET_CPR))->toBe([]);
    Http::assertNothingSent();
});
