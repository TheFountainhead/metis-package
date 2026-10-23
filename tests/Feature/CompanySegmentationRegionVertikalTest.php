<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    config()->set('metis.gating.enabled', false);
    Http::preventStrayRequests();
});

it('kan fordele paa region og vertikal og viser navnene', function (string $groupBy, string $key, string $navn) {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => $key, 'label' => null, 'count' => 7]],
        'meta' => ['total' => 7],
    ])]);

    $c = Livewire::test(CompanySegmentation::class)->set('groupBy', $groupBy);

    // 🪤 assertSee($navn) alene er blind: navnet staar ogsaa i dropdownen.
    expect($c->instance()->etiket(['key' => $key, 'label' => null]))->toBe($navn);
    $c->assertSee('7');

    Http::assertSent(fn ($r) => ($r->data()['group_by'] ?? null) === $groupBy);
})->with([
    'region' => ['region', '1082', 'Region Midtjylland'],
    'vertikal' => ['vertikal', 'oevrige', 'Øvrige brancher'],
]);

it('dropper en ukendt region eller vertikal fra URL-en i stedet for at sende den', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::withQueryParams(['region' => 'Hovedstaden', 'vertikal' => 'fintech'])
        ->test(CompanySegmentation::class)
        ->assertSet('region', '')
        ->assertSet('vertikal', '');

    Http::assertSent(fn ($r) => ! array_key_exists('region', $r->data()) && ! array_key_exists('vertikal', $r->data()));
});

it('nulstil fjerner region og vertikal', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::test(CompanySegmentation::class)
        ->set('region', '1084')
        ->set('vertikal', 'software')
        ->call('nulstil')
        ->assertSet('region', '')
        ->assertSet('vertikal', '');
});

it('har de samme vertikal-noegler som registry-api kender', function () {
    // Spejl af registry-api VerticalMapper (MAPPING-vaerdier + OEVRIGE), 23/10-2026.
    // En noegle her som datalaget ikke kender giver 422 og en fejlbesked i stedet for tal.
    $registry = ['byggeri', 'detailhandel', 'ejendom', 'energi', 'engroshandel', 'facility', 'finans', 'foedevarer',
        'foreninger', 'forretningsservice', 'forskning-udvikling', 'hardware', 'hotel-restauration', 'hr', 'industri',
        'kemi', 'landbrug', 'life-science', 'marketing', 'medier', 'miljoe', 'raadgivning', 'raastoffer', 'rejser',
        'sikkerhed', 'software', 'sport-fritid', 'telekom', 'transport-logistik', 'uddannelse', 'udlejning-leasing', 'oevrige'];

    expect(array_keys(CompanySegmentation::VERTIKALER))->toEqualCanonicalizing($registry);
});
