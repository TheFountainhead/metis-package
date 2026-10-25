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

    Livewire::withQueryParams(['region' => 'Hovedstaden', 'vertikal' => 'fintech', 'investortype' => 'familyoffice'])
        ->test(CompanySegmentation::class)
        ->assertSet('region', '')
        ->assertSet('vertikal', '')
        ->assertSet('investortype', '');

    Http::assertSent(fn ($r) => ! array_key_exists('region', $r->data())
        && ! array_key_exists('vertikal', $r->data())
        && ! array_key_exists('investortype', $r->data()));
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
    // 'sundhed' fra registry-api #381 (branche 86 ud af life science).
    // En noegle her som datalaget ikke kender giver 422 og en fejlbesked i stedet for tal.
    $registry = ['byggeri', 'detailhandel', 'ejendom', 'energi', 'engroshandel', 'facility', 'finans', 'foedevarer',
        'foreninger', 'forretningsservice', 'forskning-udvikling', 'hardware', 'hotel-restauration', 'hr', 'industri',
        'kemi', 'landbrug', 'life-science', 'marketing', 'medier', 'miljoe', 'raadgivning', 'raastoffer', 'rejser',
        'sikkerhed', 'software', 'sport-fritid', 'sundhed', 'telekom', 'transport-logistik', 'uddannelse', 'udlejning-leasing', 'oevrige'];

    expect(array_keys(CompanySegmentation::VERTIKALER))->toEqualCanonicalizing($registry);
});

it('sender investortype og viser ejerdaekningen', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => 'aps', 'label' => null, 'count' => 212]],
        'meta' => ['total' => 212, 'ejerdata' => ['med' => 13482, 'af' => 117323]],
    ])]);

    Livewire::test(CompanySegmentation::class)
        ->set('investortype', 'angel_eifo')
        ->assertSee('13.482 af 117.323')
        ->assertSee('mindst 5 %')
        ->assertSee('EIFO kræver også mindst én runde');

    Http::assertSent(fn ($r) => ($r->data()['investortype'] ?? null) === 'angel_eifo');
});

it('viser ingen ejerdaekning uden investortype', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 5]])]);

    Livewire::test(CompanySegmentation::class)->assertDontSeeHtml('data-ejerdaekning');
});

it('har de samme investortype-noegler som registry-api kender', function () {
    // Spejl af registry-api App\Enums\InvestortypeFilter, 23/10-2026.
    expect(array_keys(CompanySegmentation::INVESTORTYPER))->toEqualCanonicalizing([
        'angel_eifo', 'angel_ekstern', 'angel_flere', 'angel_person',
        'venture', 'public', 'foundation', 'udenlandsk',
    ]);
});

it('viser selskaberne uden grupperingsnoegle som egen linje, saa tabellen summer til totalen', function () {
    // Region uden filtre paa prod 23/10-2026: 407.349 i regionerne, 345.600 uden.
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => '1084', 'label' => 'Region Hovedstaden', 'count' => 600]],
        'meta' => ['total' => 1000, 'uden_segmentering' => 400],
    ])]);

    $c = Livewire::test(CompanySegmentation::class)->set('groupBy', 'region');

    $c->assertSet('udenSegmentering', 400)
        ->assertSeeHtml('data-uden-noegle')
        ->assertSee('Uden dansk region (ingen kommune i CVR, eller Grønland)')
        ->assertSee('40,0 %');
});

it('viser ingen uden-linje naar alle har en noegle', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => '1084', 'label' => 'Region Hovedstaden', 'count' => 7]],
        'meta' => ['total' => 7, 'uden_segmentering' => 0],
    ])]);

    Livewire::test(CompanySegmentation::class)->set('groupBy', 'region')
        ->assertDontSeeHtml('data-uden-noegle');
});

it('viser ikke vertikalens interne noegle ved siden af navnet', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => 'finans', 'label' => 'Finans', 'count' => 7]],
        'meta' => ['total' => 7, 'uden_segmentering' => 0],
    ])]);

    // 🪤 "finans" staar ogsaa i dropdownens option-value, saa maal paa spannet.
    Livewire::test(CompanySegmentation::class)->set('groupBy', 'vertikal')
        ->assertSee('Finans')
        ->assertDontSeeHtml('<span class="text-xs text-ink-700/40">finans</span>');
});

it('viser kommunekoden ved siden af navnet', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [['key' => '101', 'label' => null, 'count' => 7]],
        'meta' => ['total' => 7, 'uden_segmentering' => 0],
    ])]);

    Livewire::test(CompanySegmentation::class)->set('groupBy', 'municipality_code')
        ->assertSeeHtml('<span class="text-xs text-ink-700/40">101</span>');
});

it('kender de groenlandske kommuner og sundhed som vertikal', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    $c = Livewire::test(CompanySegmentation::class)->set('groupBy', 'municipality_code');

    expect($c->instance()->etiket(['key' => '956', 'label' => null]))->toBe('Sermersooq (Grønland)')
        ->and(CompanySegmentation::VERTIKALER)->toHaveKey('sundhed');
});

it('siger ikke "ingen matcher" naar alle selskaber ligger i uden-linjen', function () {
    // Filter paa kommune 956 (Groenland), fordelt paa region: ingen grupper, 3 uden.
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [],
        'meta' => ['total' => 3, 'uden_segmentering' => 3],
    ])]);

    Livewire::test(CompanySegmentation::class)->set('groupBy', 'region')
        ->assertSeeHtml('data-uden-noegle')
        ->assertDontSee('Ingen selskaber matcher de valgte afgrænsninger.');
});

it('siger stadig "ingen matcher" naar udvalget er tomt', function () {
    Http::fake(['*/company-segmentation' => Http::response([
        'data' => [],
        'meta' => ['total' => 0, 'uden_segmentering' => 0],
    ])]);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('Ingen selskaber matcher de valgte afgrænsninger.');
});

it('forklarer at "ejer i mindst 3 selskaber" kun taeller entydigt identificerede ejere', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::test(CompanySegmentation::class)->set('investortype', 'angel_flere')
        ->assertSee('Kun ejerskaber, hvor CVR identificerer personen entydigt, tæller med')
        ->assertDontSee('tallet kan være for højt');
});

it('sender fintech-filteret til datalaget', function (string $vaerdi) {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 3]])]);

    Livewire::test(CompanySegmentation::class)->set('fintech', $vaerdi);

    Http::assertSent(fn ($r) => ($r->data()['fintech'] ?? null) === $vaerdi);
})->with(['reguleret', 'udledt']);

it('dropper en ukendt fintech-vaerdi fra URL-en i stedet for at sende den', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::withQueryParams(['fintech' => 'krypto'])
        ->test(CompanySegmentation::class)
        ->assertSet('fintech', '');

    Http::assertSent(fn ($r) => ! array_key_exists('fintech', $r->data()));
});

it('nulstil fjerner fintech-filteret', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::test(CompanySegmentation::class)
        ->set('fintech', 'udledt')
        ->call('nulstil')
        ->assertSet('fintech', '');
});

it('viser forklaringen under fintech-dropdownen for det valgte', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::test(CompanySegmentation::class)
        ->assertDontSee('Crowdfunding indgår ikke endnu')
        ->set('fintech', 'reguleret')
        ->assertSee('Crowdfunding indgår ikke endnu')
        ->assertDontSee('Den udledte del er et skøn')
        ->set('fintech', 'udledt')
        ->assertSee('Den udledte del er et skøn');
});

it('har de samme fintech-noegler som registry-api kender', function () {
    // Spejl af registry-api Rule::in(['reguleret', 'udledt']), 24/10-2026.
    // En noegle her som datalaget ikke kender giver 422.
    expect(array_keys(CompanySegmentation::FINTECH))->toEqualCanonicalizing(['reguleret', 'udledt']);
    expect(array_keys(CompanySegmentation::FINTECH_FORKLARING))->toEqualCanonicalizing(['reguleret', 'udledt']);
});

it('har inklOphoerte og derefter kun de nyere properties i bunden', function () {
    // 🚨 Livewire 3.8's klient-diff sender hele komponenten under stien "" naar
    // noeglernes raekkefoelge afviger. En property indsat i midten gav 500
    // "Public property [$] not found" paa aabne faner hen over et deploy
    // (prod 23/10-2026). Nye properties skal i bunden.
    $props = array_values(array_filter(
        (new ReflectionClass(CompanySegmentation::class))->getProperties(ReflectionProperty::IS_PUBLIC),
        fn (ReflectionProperty $p) => ! $p->isStatic() && $p->getDeclaringClass()->getName() === CompanySegmentation::class,
    ));

    // Kapitalfelterne (#udviklingsstadie) og niveau (koncern) kom til EFTER inklOphoerte, i bunden.
    $navne = array_map(fn (ReflectionProperty $p) => $p->getName(), $props);

    expect(array_slice($navne, array_search('inklOphoerte', $navne, true)))->toBe([
        'inklOphoerte', 'kapitalforhoejelserMin', 'kapitalforhoejelserMax', 'rejstMin', 'rejstMax',
        'senesteForhoejelseFra', 'senesteForhoejelseTil', 'udenKapitalhistorik', 'udenKendtBeloeb', 'niveau', 'vistNiveau',
    ]);
});

it('sender inkl_ophoerte=true kun naar fluebenet er sat', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 3]])]);

    Livewire::test(CompanySegmentation::class)->call('segmentér');
    Http::assertSent(fn ($r) => ! array_key_exists('inkl_ophoerte', $r->data()));
    Http::assertNotSent(fn ($r) => array_key_exists('inkl_ophoerte', $r->data()));

    Livewire::test(CompanySegmentation::class)->set('inklOphoerte', true);
    Http::assertSent(fn ($r) => ($r->data()['inkl_ophoerte'] ?? null) === true);
});

it('sender ikke inkl_ophoerte naar fluebenet fjernes igen', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 3]])]);

    $c = Livewire::test(CompanySegmentation::class)->set('inklOphoerte', true);
    expect($c->instance()->filtre())->toHaveKey('inkl_ophoerte', true);

    $c->set('inklOphoerte', false);
    expect($c->instance()->filtre())->not->toHaveKey('inkl_ophoerte');
});

it('laeser inkl_ophoerte fra URL-en og sender den', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 3]])]);

    Livewire::withQueryParams(['inkl_ophoerte' => '1'])
        ->test(CompanySegmentation::class)
        ->call('segmentér')
        ->assertSet('inklOphoerte', true);

    Http::assertSent(fn ($r) => ($r->data()['inkl_ophoerte'] ?? null) === true);
});

it('nulstil fjerner inkl_ophoerte', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    $c = Livewire::test(CompanySegmentation::class)
        ->set('inklOphoerte', true)
        ->call('nulstil')
        ->assertSet('inklOphoerte', false);

    expect($c->instance()->filtre())->not->toHaveKey('inkl_ophoerte');
});

it('viser fluebenet "Medtag ophørte selskaber" med forklaring', function () {
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('Medtag ophørte selskaber')
        ->assertSee('Som standard tæller kun selskaber, der findes i CVR i dag.')
        ->assertSeeHtml('wire:model.live="inklOphoerte"');
});
