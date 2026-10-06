<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use TheFountainhead\Metis\Services\LookupAccess;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Regressionstests fra sikkerhedsreviewet af PR #192 (6/10-2026).
 *
 * Review: Dropbox/Frankston/Diagnostik/metis-pr192-sikkerhedsreview-2026-10-06.md
 *
 * Reviewerens fire PoC'er (A-D) beviste hver et hul ved at BESTAA. Her er de
 * vendt om: samme angrebsvej, samme HTTP-requests over `/livewire/update`,
 * men assertionen er at angrebet IKKE virker. De fejlede alle fire paa
 * `0bcdb89` (foer fix-runde 1) og bestaar efter.
 *
 * Frederiks beslutninger til fix-runden:
 *   - krydsopslag (`crossReference`) TAELLER;
 *   - kvoten er 1 gratis opslag pr. session OG hoejst 5 pr. IP pr. doegn
 *     (`metis.gating.ip_daily_limit`, standard 5, af hensyn til mobil-CGNAT).
 */
const POC_EJER = ['data' => ['property' => ['owners' => [['name' => 'EJER HEMMELIGSEN']], 'mortgages' => []]]];

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    config()->set('metis.gating.free_lookups', 1);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*/v1/property/analysis' => Http::response(POC_EJER),
        // 🪤 Tom `data` for alt andet. `Http::fake()` LAEGGER stubs til (foerste
        // match vinder), saa en senere `Http::fake()` i en test erstatter ikke
        // denne; formen skal derfor kunne renderes af alle sider.
        '*' => Http::response(['data' => []]),
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);
});

function pocLazyPayloads(string $html): array
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

function pocReplayLazy(object $test, array $payload)
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

function pocSearchSnapshot(string $html): string
{
    preg_match('/wire:snapshot="([^"]*&quot;name&quot;:&quot;metis-search&quot;[^"]*)"/', $html, $m);

    return html_entity_decode($m[1], ENT_QUOTES);
}

function pocCall(object $test, string $snapshot, string $method, array $params, array $updates = [])
{
    return $test->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]],
    ]);
}

/** Bruger IP'ens proeve(r) op med rigtige sidevisninger i hver sin session. */
function brugIpOp(object $test, int $antal): void
{
    for ($i = 0; $i < $antal; $i++) {
        $test->flushSession();
        $test->get('/lookup/address/Travervænget '.($i + 1).', 2920 Charlottenlund')
            ->assertOk()
            ->assertDontSee('Du har brugt dine gratis opslag');
    }

    $test->flushSession();
}

// ---------------------------------------------------------------------------
// K1: crossReference
// ---------------------------------------------------------------------------

it('🚨 POC A vendt: crossReference giver INTET efter at IP-proeven er brugt', function () {
    config()->set('metis.gating.ip_daily_limit', 1);

    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();
    $this->flushSession();
    $this->get('/lookup/address/Bredgade 40, 1260 København')->assertSee('Du har brugt dine gratis opslag');

    foreach (['Bredgade 40, 1260 København', 'Nyhavn 71, 1051 København'] as $adr) {
        $this->flushSession(); // ny cookie hver gang
        Http::fake(['*/v1/property/analysis' => Http::response(POC_EJER), '*' => Http::response(['data' => []])]);
        $snap = pocSearchSnapshot($this->get('/')->assertOk()->getContent());

        $html = (string) pocCall($this, $snap, 'crossReference', ['address', $adr])->assertOk()
            ->json('components.0.effects.html');

        expect($html)->not->toContain('metis-address-owners')
            ->and($html)->not->toContain('EJER HEMMELIGSEN');
        Http::assertNothingSent();
    }
});

it('🚨 crossReference taeller: ét krydsopslag pr. session, og det taeller paa IPen', function () {
    $snap = pocSearchSnapshot($this->get('/')->assertOk()->getContent());

    // Foerste krydsopslag i sessionen er sessionens ene gratis opslag.
    $svar = pocCall($this, $snap, 'crossReference', ['address', 'Bredgade 40, 1260 København'])->assertOk();
    expect((string) $svar->json('components.0.effects.html'))->toContain('metis-address-owners');
    expect(app(LookupAccess::class)->brugtFraIp())->toBe(1);

    // Positiv kontrol: dets egen sektion henter (opslaget ER godkendt).
    $ejer = pocReplayLazy($this, pocLazyPayloads((string) $svar->json('components.0.effects.html'))['metis-address-owners']);
    expect($ejer->getContent())->toContain('EJER HEMMELIGSEN');

    // Andet krydsopslag i samme session: gated.
    $snap2 = $svar->json('components.0.snapshot');
    $html2 = (string) pocCall($this, $snap2, 'crossReference', ['address', 'Nyhavn 71, 1051 København'])->assertOk()
        ->json('components.0.effects.html');
    expect($html2)->not->toContain('metis-address-owners');
});

it('🚨 K1 isoleret: et andet krydsopslag i SAMME session gates af kvote-gaten, ikke kun af rate limit', function () {
    // Rate limit (1/time for anonyme) laeser samme sessionstaeller og ville
    // ellers skjule en manglende `skalGates()` i crossReference().
    config()->set('metis.rate_limits.anonymous', 10);
    $snap = pocSearchSnapshot($this->get('/')->assertOk()->getContent());

    $svar = pocCall($this, $snap, 'crossReference', ['cvr', '37792594'])->assertOk();
    expect((string) $svar->json('components.0.effects.html'))->toContain('metis-company-info');

    $html2 = (string) pocCall($this, $svar->json('components.0.snapshot'), 'crossReference', ['cvr', '37792595'])->assertOk()
        ->json('components.0.effects.html');
    expect($html2)->not->toContain('metis-company-info');
});

it('🔑 IP-graensen er 5 pr. doegn som standard; den 6. nye session gates', function () {
    brugIpOp($this, 5);

    $this->get('/lookup/address/Nyhavn 71, 1051 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag')
        ->assertDontSee('metis-address-owners', false);
});

it('🔑 ip_daily_limit er konfigurerbar', function () {
    config()->set('metis.gating.ip_daily_limit', 2);
    brugIpOp($this, 2);

    $this->get('/lookup/cvr/37792594')->assertOk()->assertSee('Du har brugt dine gratis opslag');
});

it('🔑 1 pr. session gaelder stadig, selv med plads paa IPen', function () {
    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();

    $this->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

// ---------------------------------------------------------------------------
// V2: et uproevet token fritager ikke
// ---------------------------------------------------------------------------

it('🚨 POC B vendt: et opdigtet token fritager IKKE fra kvoten og giver ingen cachede ejere', function () {
    config()->set('metis.gating.ip_daily_limit', 1);
    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();
    $this->flushSession();
    Cache::put('metis:address_analysis:'.md5('Bredgade 40, 1260 København'), POC_EJER['data'], 3600);

    $this->withSession(['metis_user_token' => '1|x']);
    $html = $this->get('/lookup/address/Bredgade 40, 1260 København')->assertOk()
        ->assertSee('Du har brugt dine gratis opslag')->getContent();

    expect(pocLazyPayloads($html))->not->toHaveKey('metis-address-owners')
        ->and($html)->not->toContain('EJER HEMMELIGSEN');
});

it('🚨 setToken over HTTP giver ingen kvotefritagelse', function () {
    config()->set('metis.gating.ip_daily_limit', 1);
    $this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk();
    $this->flushSession();

    $html = $this->get('/alerts')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]*&quot;name&quot;:&quot;metis-alerts-inbox&quot;[^"]*)"/', $html, $m);
    expect($m)->not->toBeEmpty();

    pocCall($this, html_entity_decode($m[1], ENT_QUOTES), 'setToken', [], ['tokenInput' => '1|x'])->assertOk();
    expect(session('metis_user_token'))->toBe('1|x'); // tokenet ER sat, men fritager ikke

    $this->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

it('🚨 V2 isoleret: et token alene fritager ikke fra SESSIONSkvoten (IP har plads)', function () {
    $this->withSession(['metis_user_token' => '1|x', 'metis_lookup_count' => 1]);

    $this->get('/lookup/address/Bredgade 40, 1260 København')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

it('modstykke: en bekraeftet pilot (pilotliste via EmailGate eller pilotkonto) er fritaget', function () {
    config()->set('metis.gating.ip_daily_limit', 1);
    brugIpOp($this, 1);

    $this->withSession(['metis_user_token' => '1|ægte', 'metis_pilot_verificeret' => true, 'metis_lookup_count' => 9]);
    $this->get('/lookup/address/Bredgade 40, 1260 København')->assertOk()->assertDontSee('Du har brugt dine gratis opslag');

    $this->flushSession();
    $this->withSession(['metis_user_token' => '1|ægte', 'metis_pilot_account_id' => 7, 'metis_lookup_count' => 9]);
    $this->get('/lookup/address/Nyhavn 71, 1051 København')->assertOk()->assertDontSee('Du har brugt dine gratis opslag');
});

// ---------------------------------------------------------------------------
// V1: replay og query-manipulation paa sektionerne
// ---------------------------------------------------------------------------

it('🚨 POC C vendt: query paa en CVR-sektion kan ikke skiftes, og et replay i en frisk session henter intet', function () {
    $html = $this->get('/lookup/cvr/37792594')->assertOk()->getContent();
    $loaded = pocReplayLazy($this, pocLazyPayloads($html)['metis-company-tinglysning'])->assertOk();
    $snap = $loaded->json('components.0.snapshot');

    // Positiv kontrol: i den GODKENDTE session virker retry (og Locked paa
    // `query` braekker ikke en rigtig lazy-rundtur).
    Cache::flush(); // tinglysnings-oversigten caches 5 min; tving et rigtigt kald
    Http::fake(['*' => Http::response(['data' => ['mortgages' => []]])]);
    pocCall($this, $snap, 'retry', [])->assertOk();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/companies/37792594/tinglysning-overview'));

    // Ny anonym session, kvote 0.
    $this->flushSession();
    Http::fake(['*' => Http::response(['data' => ['mortgages' => []]])]);

    pocCall($this, $snap, 'retry', [], ['query' => '99999999']);
    pocCall($this, $snap, 'retry', []);

    Http::assertNothingSent();
});

it('🚨 V1 isoleret: query kan ikke skiftes i den GODKENDTE session heller', function () {
    // boot() ser snapshottets query (godkendt) FOER updates anvendes. Kun
    // #[Locked] stopper `updates: {query}` i den session der ejer opslaget.
    $html = $this->get('/lookup/cvr/37792594')->assertOk()->getContent();
    $snap = pocReplayLazy($this, pocLazyPayloads($html)['metis-company-tinglysning'])->assertOk()->json('components.0.snapshot');

    pocCall($this, $snap, 'retry', [], ['query' => '99999999']);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '99999999'));
});

dataset('sektionshandlere', [
    'CompanyTinglysning::retry' => ['metis-company-tinglysning', 'retry'],
    'CompanyTinglysning::pollForUpdates' => ['metis-company-tinglysning', 'pollForUpdates'],
    'CompanyStructure::loadProperties' => ['metis-company-structure', 'loadProperties'],
    'CompanyStructure::loadEnrichment' => ['metis-company-structure', 'loadEnrichment'],
    'CompanyStructure::pollForUpdates' => ['metis-company-structure', 'pollForUpdates'],
    'CompanyProperties::loadMore' => ['metis-company-properties', 'loadMore'],
    'CompanyProperties::pollForUpdates' => ['metis-company-properties', 'pollForUpdates'],
]);

it('🚨 en sektions refresh-handler henter intet i en frisk session', function (string $navn, string $metode) {
    $html = $this->get('/lookup/cvr/37792594')->assertOk()->getContent();
    $snap = pocReplayLazy($this, pocLazyPayloads($html)[$navn])->assertOk()->json('components.0.snapshot');

    $this->flushSession();
    Http::fake(['*' => Http::response(['data' => []])]);

    $svar = pocCall($this, $snap, $metode, []);

    expect($svar->status())->toBe(403);
    Http::assertNothingSent();
})->with('sektionshandlere');

it('🚨 et CVR-lazy-payload genbrugt i en frisk session henter intet', function (string $navn) {
    $payloads = pocLazyPayloads($this->get('/lookup/cvr/37792594')->assertOk()->getContent());

    $this->flushSession();
    Http::fake(['*' => Http::response(['data' => ['name' => 'HEMMELIG ApS']])]);

    pocReplayLazy($this, $payloads[$navn]);

    Http::assertNothingSent();
})->with([
    'metis-company-info', 'metis-company-overview', 'metis-company-funding', 'metis-company-roles',
    'metis-company-structure', 'metis-company-relations', 'metis-company-properties', 'metis-company-tinglysning',
    'metis-lookup-title',
]);

it('🚨 et adresse-lazy-payload genbrugt i en frisk session henter intet', function () {
    $payloads = pocLazyPayloads($this->get('/lookup/address/Travervænget 3, 2920 Charlottenlund')->assertOk()->getContent());

    $this->flushSession();
    Http::fake(['*' => Http::response(POC_EJER)]);

    $svar = pocReplayLazy($this, $payloads['metis-address-owners']);

    expect($svar->getContent())->not->toContain('EJER HEMMELIGSEN');
    Http::assertNothingSent();
});

it('🚨 POC D vendt: datalaget udleverer ikke en adresse sessionen ikke har faaet godkendt', function () {
    $this->withSession(['metis_lookup_count' => 1]);
    $api = app(RegistryApi::class);

    expect($api->resolveAddressAnalysis('Nyhavn 71, 1051 København'))->not->toHaveKey('property');
    Http::assertNothingSent();
});

it('modstykke: datalaget udleverer den adresse sessionen HAR faaet godkendt', function () {
    $this->withSession(['metis_lookup_count' => 0]);
    expect(app(LookupAccess::class)->godkendOpslag('address', 'Nyhavn 71, 1051 København'))->toBeTrue();
    session(['metis_lookup_count' => 1]);

    expect(app(RegistryApi::class)->resolveAddressAnalysis('Nyhavn 71, 1051 København'))->toHaveKey('property');
});

it('🚨 Search::$result kan ikke saettes af klienten', function () {
    $snap = pocSearchSnapshot($this->get('/')->assertOk()->getContent());

    $svar = pocCall($this, $snap, 'retrySection', ['valuation'], ['result' => ['property' => ['matrikel' => 'X']]]);

    expect($svar->status())->not->toBe(200);
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// M1: atomisk IP-taeller
// ---------------------------------------------------------------------------

it('🚨 M1: to samtidige nye sessioner kan ikke begge faa den sidste IP-plads', function () {
    config()->set('metis.gating.ip_daily_limit', 1);
    $adgang = app(LookupAccess::class);
    $this->withSession([]);

    // Begge requests har LAEST taelleren (0) og passeret visningsgaten …
    expect($adgang->anonymtOpslagTilladt())->toBeTrue();

    // … men kun den ene faar reservationen.
    expect($adgang->godkendOpslag('address', 'A, 2920'))->toBeTrue();
    session()->flush(); // anden session, samme IP
    expect($adgang->godkendOpslag('address', 'B, 2920'))->toBeFalse()
        ->and($adgang->brugtFraIp())->toBe(1);
});
