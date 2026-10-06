<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Lookup;
use TheFountainhead\Metis\Services\LookupAccess;
use TheFountainhead\Metis\Services\QuotaExceededException;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Anonym adgang til /lookup — Frederiks beslutning 6/10-2026.
 *
 *   1. Person- og CPR-opslag kraever en identificeret bruger.
 *   2. Adresse/CVR forbliver en gratis proeve, men kvoten er bundet til IP'en,
 *      saa en ny cookie ikke giver et nyt opslag.
 *   3. Kvote-hullerne fra undersoegelsen (pooled kald, cache foer gate,
 *      forskellige taerskler) er lukket.
 *
 * Undersoegelsen: Dropbox/Frankston/Diagnostik/metis-lookup-adgangskontrol-2026-10-06.md
 *
 * 🔑 LEKTIONEN FRA #185: en gate paa siden daekker ikke sektionerne. Hver
 * sektion er en selvstaendig Livewire-komponent, der kan kaldes direkte over
 * `/livewire/update`. Derfor testes baade siden, sektionen mountet direkte,
 * et RIGTIGT replay af et lazy-payload over HTTP, og datalaget selv.
 */
const PERSON_SVAR = [
    'data' => [
        'person_name' => 'HEMMELIG PERSON',
        'address' => ['street' => 'Hemmelig Vej 1'],
        'companies' => [[
            'name' => 'HEMMELIG ApS',
            'cvr' => '11111111',
            'status' => 'NORMAL',
            'roles' => [['role_label' => 'Direktør', 'is_current' => true]],
        ]],
        'properties' => [],
    ],
];

const ANDEN_IP = '203.0.113.20';
const EN_IP = '203.0.113.10';

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    config()->set('metis.gating.free_lookups', 1);
    // 🪤 IP-graensen er 5 som standard (Frederik 6/10, fix-runde 1). Testene
    // her blev skrevet mod 1 og beviser bindingen tydeligst dér; standarden
    // paa 5 testes i ReviewPocRegressionTest.
    config()->set('metis.gating.ip_daily_limit', 1);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(PERSON_SVAR)]);
    $this->withServerVariables(['REMOTE_ADDR' => EN_IP]);
});

/** Lazy-placeholderne paa en side: navn => [snapshot-json, encoded mount-params]. */
function lazyPayloads(string $html): array
{
    $ud = [];

    preg_match_all('/<[a-z]+\s[^>]*wire:snapshot="[^"]*"[^>]*>/i', $html, $tags);

    foreach ($tags[0] as $tag) {
        if (! preg_match('/wire:snapshot="([^"]*)"/', $tag, $s)
            || ! preg_match("/__lazyLoad\\(&#039;([^&]+)&#039;\\)|__lazyLoad\\('([^']+)'\\)/", $tag, $l)) {
            continue;
        }

        $snapshot = html_entity_decode($s[1], ENT_QUOTES);
        $navn = json_decode($snapshot, true)['memo']['name'] ?? null;

        $ud[$navn] = [$snapshot, $l[1] !== '' ? $l[1] : $l[2]];
    }

    return $ud;
}

function replayLazy(object $test, array $payload)
{
    [$snapshot, $encoded] = $payload;

    return $test->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => '__lazyLoad', 'params' => [$encoded]]],
        ]],
    ]);
}

function identificeret(object $test): void
{
    $test->withSession(['metis_verified_email' => 'pilot@frankston.io', 'metis_user_token' => '1|pilot']);
}

// ---------------------------------------------------------------------------
// 1. Person- og CPR-opslag kraever login
// ---------------------------------------------------------------------------

it('🚨 en anonym personside viser login-gaten og ingen sektioner', function () {
    $svar = $this->get('/lookup/person/Lars Larsen')->assertOk();

    $svar->assertSee('Personopslag kræver at du er tilmeldt')
        ->assertDontSee('metis-person-structure', false)
        ->assertDontSee('metis-person-roles', false);

    Http::assertNothingSent();
});

it('🚨 en anonym CPR-side viser login-gaten og ingen sektioner', function () {
    $svar = $this->get('/lookup/cpr/311278-1234')->assertOk();

    $svar->assertSee('Personopslag kræver at du er tilmeldt')
        ->assertDontSee('metis-person-summary', false)
        ->assertDontSee('metis-person-companies', false);

    Http::assertNothingSent();
});

it('🪤 login-gaten bruger ikke af den anonyme kvote', function () {
    $this->get('/lookup/person/Lars Larsen')->assertOk();

    expect(session('metis_lookup_count', 0))->toBe(0)
        ->and(app(LookupAccess::class)->brugtFraIp())->toBe(0);

    // ... saa et adresseopslag bagefter stadig er gratis.
    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')
        ->assertOk()
        ->assertSee('metis-address-owners', false);
});

it('🚨 login-gaten aabner den eksisterende tilmeldings-dialog', function () {
    Livewire::test(Lookup::class, ['type' => 'person', 'query' => 'Lars Larsen'])
        ->assertSet('kraeverLogin', true)
        ->assertDispatched('show-email-gate');
});

it('🚨 gaten er case-insensitiv paa typen', function () {
    Livewire::test(Lookup::class, ['type' => 'CPR', 'query' => '311278-1234'])
        ->assertSet('kraeverLogin', true);
});

dataset('personsektioner', [
    'person-roles (navn)' => ['metis-person-roles', ['query' => 'Lars Larsen']],
    'person-structure (navn)' => ['metis-person-structure', ['query' => 'Lars Larsen', 'source' => 'name']],
    'person-structure (cpr)' => ['metis-person-structure', ['query' => '3112781234']],
    'person-companies' => ['metis-person-companies', ['query' => '3112781234']],
    'person-info' => ['metis-person-info', ['query' => '3112781234']],
    'person-properties' => ['metis-person-properties', ['query' => '3112781234']],
    'person-relations' => ['metis-person-relations', ['query' => '3112781234']],
    'person-summary' => ['metis-person-summary', ['query' => '3112781234']],
]);

it('🚨 en personsektion mountet DIREKTE af en anonym henter intet', function (string $navn, array $params) {
    // Praecis hvad et `__lazyLoad` over `/livewire/update` goer: sektionen
    // mountes uden om `Lookup::mount()`. Frisk session, kvote 0.
    $this->withSession(['metis_lookup_count' => 0]);

    $c = Livewire::test($navn, $params);

    Http::assertNothingSent();
    expect(json_encode($c->instance()->all()))->not->toContain('HEMMELIG');
})->with('personsektioner');

it('modstykke: en IDENTIFICERET bruger faar personsektionen', function (string $navn, array $params) {
    identificeret($this);

    Livewire::test($navn, $params);

    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://registry-api.test/v1/'));
})->with('personsektioner');

it('🚨 REPLAY: et lazy-payload fra en identificeret session giver en anonym INGEN persondata', function () {
    // Den virkelige angrebsvej. Livewires checksum er bundet til APP_KEY, ikke
    // til sessionen, saa et payload udstedt til én bruger kan sendes af en
    // anden. Uden datalags-gaten ville en anonym kunne genbruge det.
    identificeret($this);
    $payloads = lazyPayloads($this->get('/lookup/person/Lars Larsen')->assertOk()->getContent());

    expect($payloads)->toHaveKey('metis-person-roles');

    // Positiv kontrol: SAMME replay i den identificerede session henter data.
    // Uden den kunne den negative assertion nedenfor vaere trivielt sand
    // (fx hvis replay-mekanikken selv var i stykker).
    replayLazy($this, $payloads['metis-person-roles'])->assertOk()->assertSee('HEMMELIG PERSON');

    // Ny, anonym session — samme payload.
    $this->flushSession();
    Http::fake(['*' => Http::response(PERSON_SVAR)]);

    $svar = replayLazy($this, $payloads['metis-person-roles'])->assertOk();

    expect($svar->getContent())->not->toContain('HEMMELIG');
    Http::assertNothingSent();
});

it('🚨 en anonym session faar ingen CPR-data fra datalaget, heller ikke fra cachen', function () {
    $this->withSession(['metis_lookup_count' => 0]);
    $api = app(RegistryApi::class);

    // Cachen er varm fra en identificeret brugers opslag for 2 minutter siden.
    Cache::put('metis:companies_by_cpr:'.sha1('3112781234'), ['companies' => [['name' => 'HEMMELIG ApS']]], 300);
    Cache::put('metis:person_property_portfolio:'.sha1('3112781234'), ['companies' => [['name' => 'HEMMELIG ApS']]], 300);

    $svar = [
        $api->fetchCompaniesByCpr('3112781234'),
        $api->fetchCompaniesByCprCached('3112781234'),
        $api->fetchPropertiesByCpr('3112781234'),
        $api->fetchPersonPropertyPortfolioByCpr('3112781234'),
        $api->fetchPersonPropertyPortfolioByCprCached('3112781234'),
        $api->fetchPersonPropertyPortfolioByCprFromCache('3112781234'),
        $api->fetchPersonRoles('Lars Larsen'),
        $api->fetchCompaniesByName('Lars Larsen'),
        $api->fetchPersonPropertyPortfolio('Lars Larsen'),
        $api->disambiguatePerson('Lars Larsen'),
        $api->fetchRolesByCvr(['11111111'], '3112781234'),
        $api->searchPersonByName('Lars Larsen'),
    ];

    expect(json_encode($svar))->not->toContain('HEMMELIG');
    Http::assertNothingSent();
});

it('modstykke: en identificeret bruger faar CPR-data, ogsaa fra cachen', function () {
    identificeret($this);
    Cache::put('metis:companies_by_cpr:'.sha1('3112781234'), ['companies' => [['name' => 'CACHET ApS']]], 300);

    $api = app(RegistryApi::class);

    expect(json_encode($api->fetchCompaniesByCprCached('3112781234')))->toContain('CACHET ApS')
        ->and(json_encode($api->fetchPersonRoles('Lars Larsen')))->toContain('HEMMELIG PERSON');
});

it('🚨 et token ALENE er ikke en identifikation', function () {
    // `AlertsInbox::setToken()` accepterer enhver streng paa formen
    // `<tal>|<tegn>` uden at proeve den mod registry-api. Taltes tokenet som
    // identifikation, kunne en anonym skrive `1|x` og faa en identificeret
    // brugers CACHEDE CPR-svar — cachen spoerger aldrig registry-api.
    $this->withSession(['metis_user_token' => '1|opdigtet']);
    Cache::put('metis:companies_by_cpr:'.sha1('3112781234'), ['companies' => [['name' => 'HEMMELIG ApS']]], 300);

    expect(json_encode(app(RegistryApi::class)->fetchCompaniesByCprCached('3112781234')))->not->toContain('HEMMELIG');

    $this->get('/lookup/person/Lars Larsen')
        ->assertOk()
        ->assertSee('Personopslag kræver at du er tilmeldt');
});

it('🚨 en identificeret bruger ser personsiden med sektioner', function () {
    identificeret($this);

    $this->get('/lookup/person/Lars Larsen')
        ->assertOk()
        ->assertDontSee('Personopslag kræver at du er tilmeldt')
        ->assertSee('metis-person-roles', false);

    $this->get('/lookup/cpr/311278-1234')
        ->assertOk()
        ->assertSee('metis-person-companies', false);
});

it('🚨 email-verified-eventet kan IKKE bruges til at udnaevne sig selv', function () {
    // `Lookup::onEmailVerified()` er en offentlig metode. Skrev den sin
    // parameter i sessionen, kunne enhver anonym kalde den over
    // `/livewire/update` med en opdigtet mail og blive "identificeret".
    $c = Livewire::test(Lookup::class, ['type' => 'person', 'query' => 'Lars Larsen']);

    $c->call('onEmailVerified', 'opdigtet@angriber.dk');

    expect(session('metis_verified_email'))->toBeNull();
});

it('🚨 det samme gaelder forsidens soegning', function () {
    Livewire::test(\TheFountainhead\Metis\Livewire\Search::class)
        ->call('onEmailVerified', 'opdigtet@angriber.dk');

    expect(session('metis_verified_email'))->toBeNull();
});

it('🚨 forsidens navnesoegning spoerger ikke om personer for en anonym', function () {
    // En frisk anonym session, som paa forsiden. Uden startet session er
    // kvoten brugt (M4), og testen ville maale den gren i stedet.
    session()->start();

    Http::fake([
        '*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => [['name' => 'LARSEN A/S', 'cvr' => '22222222']]]]),
        '*' => Http::response(PERSON_SVAR),
    ]);

    Livewire::test(\TheFountainhead\Metis\Livewire\Search::class)
        ->set('query', 'Lars Larsen')
        ->call('search')
        ->assertSet('personerKraeverLogin', true)
        ->assertSet('error', false) // ikke "Ingen resultater": vi spurgte ikke
        ->assertSee('Personopslag kræver at du er tilmeldt.')
        ->assertDontSee('HEMMELIG');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'person-roles'));
});

// ---------------------------------------------------------------------------
// 2. Adresse/CVR: holdbar gratis proeve, bundet til IP
// ---------------------------------------------------------------------------

it('🚨 et anonymt adresseopslag virker én gang; ny session paa SAMME IP gates', function () {
    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag')
        ->assertSee('metis-address-owners', false);

    // Ryd cookies = ny session. Foer denne aendring gav det et nyt opslag.
    $this->flushSession();

    $this->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag')
        ->assertDontSee('metis-address-owners', false);
});

it('🚨 det samme gaelder CVR', function () {
    $this->get('/lookup/cvr/37792594')->assertOk()->assertDontSee('Du har brugt dine gratis opslag');
    $this->flushSession();
    $this->get('/lookup/cvr/37792595')->assertOk()->assertSee('Du har brugt dine gratis opslag');
});

it('en ANDEN IP faar sin egen proeve', function () {
    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();
    $this->flushSession();

    $this->withServerVariables(['REMOTE_ADDR' => ANDEN_IP])
        ->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag')
        ->assertSee('metis-address-owners', false);
});

it('🚨 bag Cloudflare taeller CF-Connecting-IP, ikke edge-IPen', function () {
    // Uden dette ville alle besoegende bag samme Cloudflare-edge dele ÉN
    // kvote — den ene ville bruge den, og alle andre ville vaere gated.
    $this->withServerVariables(['REMOTE_ADDR' => '172.64.1.1'])
        ->withHeaders(['CF-Connecting-IP' => EN_IP])
        ->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');

    $this->flushSession();

    // En anden besoegende gennem SAMME edge: egen proeve.
    $this->withServerVariables(['REMOTE_ADDR' => '172.64.1.1'])
        ->withHeaders(['CF-Connecting-IP' => ANDEN_IP])
        ->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');

    $this->flushSession();

    // Den foerste igen, nu via en ANDEN edge: stadig gated.
    $this->withServerVariables(['REMOTE_ADDR' => '162.158.9.9'])
        ->withHeaders(['CF-Connecting-IP' => EN_IP])
        ->get('/lookup/address/Nyhavn 71, 1051 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

it('🚨 CF-Connecting-IP fra en IKKE-Cloudflare-afsender ignoreres', function () {
    // Ellers kunne man ramme origin direkte og skrive en ny IP pr. request.
    $this->withHeaders(['CF-Connecting-IP' => '198.51.100.1'])
        ->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();

    $this->flushSession();

    $this->withHeaders(['CF-Connecting-IP' => '198.51.100.2'])
        ->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

it('🪤 IPv6 bindes paa /56 (og dermed ogsaa inden for et /64)', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::1'])
        ->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();

    $this->flushSession();

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:ffff::9'])
        ->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');

    // Et andet /64 i samme /56 (review M2): stadig samme abonnent.
    $this->flushSession();

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])
        ->get('/lookup/address/Nyhavn 71, 1051 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

it('IP-kvoten udloeber efter vinduet', function () {
    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();
    $this->flushSession();

    $this->travel(25)->hours();

    $this->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');
});

it('forsidens soegning taeller ogsaa paa IPen', function () {
    // 🪤 En RIGTIG request (`/?q=`), ikke Livewire::test(): testharnessens
    // interne request baerer ikke `withServerVariables`, saa taelleren ville
    // ende paa 127.0.0.1 og testen maale en anden IP end den paastaar.
    $this->get('/?q=37792594')->assertOk()->assertSee('metis-company-info', false);

    $this->flushSession();

    $this->get('/lookup/cvr/37792595')->assertOk()->assertSee('Du har brugt dine gratis opslag');
});

it('en verificeret bruger rammes ikke af IP-kvoten og taeller ikke paa den', function () {
    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();
    $this->flushSession();

    $this->withSession(['metis_verified_email' => 'kollega@frankston.io']);

    $this->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');

    expect(app(LookupAccess::class)->brugtFraIp())->toBe(1);
});

// ---------------------------------------------------------------------------
// 3. Kvote-hullerne
// ---------------------------------------------------------------------------

it('🚨 fetchCompanyInfosPooled respekterer kvote-gaten', function () {
    $this->withSession(['metis_lookup_count' => 999]);

    expect(fn () => app(RegistryApi::class)->fetchCompanyInfosPooled(['11111111', '22222222']))
        ->toThrow(QuotaExceededException::class);

    Http::assertNothingSent();
});

it('🚨 fetchCompanyStructuresPooled respekterer kvote-gaten', function () {
    $this->withSession(['metis_lookup_count' => 999]);

    expect(fn () => app(RegistryApi::class)->fetchCompanyStructuresPooled(['11111111']))
        ->toThrow(QuotaExceededException::class);

    Http::assertNothingSent();
});

it('modstykke: de pooled kald virker inden for kvoten', function () {
    $this->withSession(['metis_lookup_count' => 1]);

    app(RegistryApi::class)->fetchCompanyInfosPooled(['11111111']);
    app(RegistryApi::class)->fetchCompanyStructuresPooled(['11111111']);

    Http::assertSentCount(2);
});

dataset('cachede_opslag', [
    'resolveAddressAnalysis' => [
        function () {
            Cache::put('metis:address_analysis:'.md5('Travervænget 3, 2920 Charlottenlund'), ['property' => ['owner' => 'HEMMELIG EJER']], 3600);
            // Fix-runde 1: datalaget kraever ogsaa at adressen er godkendt i
            // sessionen. Den er det her, saa testen maaler kun kvote-gaten.
            app(LookupAccess::class)->godkendOpslag('address', 'Travervænget 3, 2920 Charlottenlund');
        },
        fn (RegistryApi $api) => $api->resolveAddressAnalysis('Travervænget 3, 2920 Charlottenlund'),
    ],
    'fetchCompanyInfo' => [
        fn () => Cache::put('metis:company_info:11111111', ['name' => 'HEMMELIG ApS'], 3600),
        fn (RegistryApi $api) => $api->fetchCompanyInfo('11111111'),
    ],
    'fetchCompanyInfosPooled' => [
        fn () => Cache::put('metis:company_info:11111111', ['name' => 'HEMMELIG ApS'], 3600),
        fn (RegistryApi $api) => $api->fetchCompanyInfosPooled(['11111111']),
    ],
    'fetchCompanyInfosCached' => [
        fn () => Cache::put('metis:company_info:11111111', ['name' => 'HEMMELIG ApS'], 3600),
        fn (RegistryApi $api) => $api->fetchCompanyInfosCached(['11111111']),
    ],
]);

it('🚨 et cache-hit springer IKKE kvote-gaten over', function (Closure $seed, Closure $kald) {
    // 🪤 Sessionen FOER seed: `godkendOpslag()` i seed skriver paa sessionens
    // godkendelsesliste og afviser uden startet session (M4).
    $this->withSession(['metis_lookup_count' => 999]);
    $seed();
    $api = app(RegistryApi::class);

    // Positiv kontrol paa noeglen: inden for kvoten KOMMER det cachede svar.
    // Uden den kunne testen bestaa fordi seed-noeglen var forkert.
    session(['metis_lookup_count' => 1]);
    expect(json_encode($kald($api)))->toContain('HEMMELIG');

    session(['metis_lookup_count' => 999]);
    $svar = rescue(fn () => $kald($api), 'BLOKERET', false);

    expect(json_encode($svar))->not->toContain('HEMMELIG');
    Http::assertNothingSent();
})->with('cachede_opslag');

it('🔑 siden og datalaget bruger samme taerskel', function () {
    $adgang = app(LookupAccess::class);
    // 🪤 IKKE `$this->get('/')`: en afsluttet request GEMMER sessionen, og
    // `Store::save()` saetter `started = false`. Datalaget ville da se en
    // sessionsloes kontekst (baggrundsjob) og aldrig gate — testen ville
    // maale en undtagelse, ikke reglen.
    session()->start();

    // free_lookups = 1: opslag nr. 1 er tilladt, nr. 2 er ikke.
    session(['metis_lookup_count' => 0]);
    expect($adgang->anonymtOpslagTilladt())->toBeTrue()
        ->and($adgang->anonymKvoteOverskredet())->toBeFalse();

    // Efter ét opslag: siden maa ikke starte et nyt, men sessionens eget
    // opslag (dets sektioner) maa stadig hente.
    session(['metis_lookup_count' => 1]);
    expect($adgang->anonymtOpslagTilladt())->toBeFalse()
        ->and($adgang->anonymKvoteOverskredet())->toBeFalse();

    session(['metis_lookup_count' => 2]);
    expect($adgang->anonymKvoteOverskredet())->toBeTrue();

    // free_lookups = 3 flytter BEGGE graenser.
    config()->set('metis.gating.free_lookups', 3);
    session(['metis_lookup_count' => 2]);
    expect($adgang->anonymtOpslagTilladt())->toBeTrue()
        ->and($adgang->anonymKvoteOverskredet())->toBeFalse();
});

it('🔑 taersklen findes kun ét sted i koden', function () {
    // Drift-vagt: `free_lookups` og `metis_lookup_count >`/`>=` maa ikke
    // genopstaa som egne kopier. Det var praecis saadan `>=` og `>` opstod.
    $fund = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $fil) {
        if ($fil->getExtension() !== 'php' || str_ends_with($fil->getPathname(), 'Services/LookupAccess.php')) {
            continue;
        }

        $kode = collect(metisKodeTokens($fil->getPathname()))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)
            ->implode('');

        if (str_contains($kode, "'metis.gating.free_lookups'")) {
            $fund[] = $fil->getFilename();
        }
    }

    expect($fund)->toBe([]);
});

// ---------------------------------------------------------------------------
// 4. Klienten kan ikke selv bytte opslaget ud
// ---------------------------------------------------------------------------

it('🚨 Lookup: query, type og gate-flagene kan ikke saettes af klienten', function (string $felt, mixed $vaerdi) {
    // Uden #[Locked] kunne én tilladt side genbruges til ubegraensede opslag:
    // `updates: {query: "<ny adresse>"}` re-renderer sektionerne for den nye
    // adresse, og datalagets sessionskvote ser det som det samme opslag.
    $c = Livewire::test(Lookup::class, ['type' => 'address', 'query' => 'Travervænget 3, 2920 Charlottenlund']);

    expect(fn () => $c->set($felt, $vaerdi))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
})->with([
    'query' => ['query', 'Bredgade 40, 1260 København'],
    'type' => ['type', 'cvr'],
    'gated' => ['gated', false],
    'kraeverLogin' => ['kraeverLogin', false],
    'ufuldstaendigAdresse' => ['ufuldstaendigAdresse', false],
]);

it('🚨 Search: resultType kan ikke saettes af klienten', function () {
    $c = Livewire::test(\TheFountainhead\Metis\Livewire\Search::class);

    expect(fn () => $c->set('resultType', 'cvr'))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
});

it('🪤 Locked braekker ikke en RIGTIG runde over /livewire/update', function () {
    // PersonStructure:200: Locked braekkede engang en lazy komponent i prod,
    // mens alle Livewire::test() var groenne. Derfor her en rigtig request
    // med sidens eget snapshot, ikke testharnessen.
    $html = $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]*&quot;name&quot;:&quot;metis-lookup&quot;[^"]*)"/', $html, $m);
    expect($m)->not->toBeEmpty();

    $svar = $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => html_entity_decode($m[1], ENT_QUOTES),
            'updates' => (object) [],
            'calls' => [],
        ]],
    ])->assertOk();

    // Sektionerne er stadig paa siden efter rundturen (titel + 13 sektioner
    // genbrugt som boern), og hydreringen af de laaste felter gik igennem.
    $snapshot = json_decode($svar->json('components.0.snapshot'), true);
    expect($snapshot['data']['query'])->toBe('Travervænget 3, 2920 Charlottenlund')
        ->and($snapshot['memo']['children'])->toHaveCount(14);
});
