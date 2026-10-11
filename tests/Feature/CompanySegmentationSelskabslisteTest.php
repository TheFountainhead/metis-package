<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Klik paa en gruppe ⇒ selskaberne bag tallet ("taellinger og lister",
 * ERST-tilbuddets Bilag 2.2). Licensydelse: kun bekraeftede piloter.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    Cache::flush();
    Http::preventStrayRequests();
});


/**
 * 🪤 Ikke i beforeEach: `Http::fake()` LAEGGER stubs til (foerste match vinder),
 * saa fejl-testen ville ellers faa 200-svaret.
 */
function fakeListeOk(): void
{
    Http::fake([
        '*/company-segmentation/companies' => Http::response([
            'data' => [
                ['cvr' => '40447024', 'name' => 'HEMMELIGT SELSKAB ApS', 'company_type' => 'aps', 'industry_code' => '62.01.00', 'industry_description' => 'Computerprogrammering', 'municipality_code' => '751', 'founded_date' => '2019-03-01', 'max_fte' => 42],
            ],
            'meta' => ['total' => 6924, 'page' => 1, 'per_page' => 50, 'last_page' => 139],
        ]),
        '*/company-segmentation' => Http::response([
            'data' => [['key' => '751', 'label' => null, 'count' => 6924]],
            'meta' => ['total' => 117382],
        ]),
    ]);
}

function erListekald($r): bool
{
    return str_contains($r->url(), '/company-segmentation/companies');
}

it('viser selskaberne bag en gruppe, med link til opslaget', function () {
    fakeListeOk();
    config()->set('metis.gating.enabled', false);

    Livewire::test(CompanySegmentation::class)
        ->call('visSelskaber', '751')
        ->assertSet('valgtGruppe', '751')
        ->assertSet('selskabTotal', 6924)
        ->assertSee('HEMMELIGT SELSKAB ApS')
        ->assertSee('/lookup/cvr/40447024')
        ->assertSee('Side 1 af 139');
});

it('🚨 sender SAMME filtre som taellingen, plus gruppen og siden', function () {
    fakeListeOk();
    config()->set('metis.gating.enabled', false);

    Livewire::test(CompanySegmentation::class)
        ->set('ivaerksaetter', true)
        ->call('visSelskaber', '751')
        ->call('gaaTilSide', 2);

    Http::assertSent(fn ($r) => erListekald($r)
        && $r['group_by'] === 'municipality_code'
        && $r['group_key'] === '751'
        && $r['ivaerksaetter'] === true
        && $r['page'] === 2);
});

it('🚨 lukker listen naar et filter aendres, saa den aldrig staar under forkerte tal', function () {
    fakeListeOk();
    config()->set('metis.gating.enabled', false);

    Livewire::test(CompanySegmentation::class)
        ->call('visSelskaber', '751')
        ->assertSet('valgtGruppe', '751')
        ->set('ivaerksaetter', true)
        ->assertSet('valgtGruppe', null)
        ->assertSet('selskaber', [])
        ->assertDontSee('HEMMELIGT SELSKAB ApS');
});

it('aabner kun en gruppe der staar i tabellen', function () {
    fakeListeOk();
    config()->set('metis.gating.enabled', false);

    Livewire::test(CompanySegmentation::class)
        ->call('visSelskaber', '999')
        ->assertSet('valgtGruppe', null);

    Http::assertNotSent(fn ($r) => erListekald($r));
});

it('🚨 datalaget afviser en ikke-pilot uden et HTTP-kald', function () {
    fakeListeOk();
    config()->set('metis.gating.enabled', true);
    $this->withSession(['metis_verified_email' => 'kunde@firma.dk', 'metis_user_token' => '1|x']);

    expect(app(RegistryApi::class)->segmentationCompanies('municipality_code', '751'))
        ->toBe(['error' => 'pilot_required', 'status' => 403]);

    Http::assertNothingSent();
});

it('🚨 en ikke-pilot faar ingen liste over /livewire/update, heller ikke med en PILOTS snapshot', function () {
    fakeListeOk();
    config()->set('metis.gating.enabled', true);
    $this->withSession(['metis_user_token' => '1|aegte', 'metis_pilot_account_id' => 7]);
    $html = $this->get('/segmentering')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]*company-segmentation[^"]*)"/', $html, $m);
    $snap = html_entity_decode($m[1], ENT_QUOTES);

    $this->flushSession();

    $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snap,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => 'visSelskaber', 'params' => ['751']]],
        ]],
    ])->assertOk()->assertDontSee('HEMMELIGT SELSKAB');

    Http::assertNotSent(fn ($r) => erListekald($r));
});

it('viser en fejlbesked, ikke en tom liste, naar API-et svigter', function () {
    config()->set('metis.gating.enabled', false);
    Http::fake([
        '*/company-segmentation/companies' => Http::response([], 500),
        '*/company-segmentation' => Http::response(['data' => [['key' => '751', 'label' => null, 'count' => 6924]], 'meta' => ['total' => 117382]]),
    ]);

    Livewire::test(CompanySegmentation::class)
        ->call('visSelskaber', '751')
        ->assertSee('Selskaberne kunne ikke hentes')
        ->assertDontSee('Ingen selskaber i gruppen');
});

it('🚨 ingen offentlig metode hedder det samme som en offentlig egenskab', function () {
    // Livewires $wire-proxy slaar state op FOER metoder: en metode med samme
    // navn som en egenskab kan aldrig kaldes fra browseren. Livewire::test()
    // ser det ikke, fordi ->call() gaar uden om proxyen.
    $klasse = new ReflectionClass(CompanySegmentation::class);
    $egenskaber = collect($klasse->getProperties(ReflectionProperty::IS_PUBLIC))->map->getName();
    $metoder = collect($klasse->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn ($m) => $m->getDeclaringClass()->getName() === CompanySegmentation::class)
        ->map->getName();

    expect($metoder->intersect($egenskaber)->values()->all())->toBe([]);
});

it('🚨 listen bruger de filtre der GAV tallene, ikke et felt der er aendret siden', function () {
    fakeListeOk();
    config()->set('metis.gating.enabled', false);

    // industryPrefix har ingen updated-hook: et .blur-felt kan synce i samme
    // request som klikket uden at taellingen er koert igen.
    Livewire::test(CompanySegmentation::class)
        ->set('industryPrefix', '62')
        ->call('visSelskaber', '751');

    Http::assertSent(fn ($r) => erListekald($r) && ! isset($r['industry_prefix']));
});

it('🚨 en payload kan ikke saette den valgte gruppe eller tabellen', function () {
    fakeListeOk();
    config()->set('metis.gating.enabled', false);

    expect(fn () => Livewire::test(CompanySegmentation::class)->set('valgtGruppe', '999'))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(CompanySegmentation::class)->set('grupper', [['key' => '999', 'count' => 1]]))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

    Http::assertNotSent(fn ($r) => erListekald($r));
});

it('crasher ikke paa en ulaeselig stiftelsesdato', function () {
    config()->set('metis.gating.enabled', false);
    Http::fake([
        '*/company-segmentation/companies' => Http::response(['data' => [['cvr' => '40447024', 'name' => 'X ApS', 'founded_date' => 'ukendt']], 'meta' => ['total' => 1, 'last_page' => 1]]),
        '*/company-segmentation' => Http::response(['data' => [['key' => '751', 'label' => null, 'count' => 1]], 'meta' => ['total' => 1]]),
    ]);

    Livewire::test(CompanySegmentation::class)->call('visSelskaber', '751')->assertOk()->assertSee('X ApS');
});

it('regner sider ud fra totalen naar last_page mangler, og siger fra naar listen er afkortet', function () {
    config()->set('metis.gating.enabled', false);
    Http::fake([
        '*/company-segmentation/companies' => Http::response(['data' => [['cvr' => '1', 'name' => 'A']], 'meta' => ['total' => 6924, 'capped' => false]]),
        '*/company-segmentation' => Http::response(['data' => [['key' => '751', 'label' => null, 'count' => 6924]], 'meta' => ['total' => 6924]]),
    ]);

    Livewire::test(CompanySegmentation::class)->call('visSelskaber', '751')->assertSet('sider', 139);
});
