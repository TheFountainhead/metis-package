<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use TheFountainhead\Metis\MetisServiceProvider;
use TheFountainhead\Metis\Services\LookupAccess;
use TheFountainhead\Metis\Services\QuotaExceededException;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Opfoelgning paa PR #192 (re-review, opfoelgning 2, 3 og 5).
 *
 *   - Fejler IP-taelleren (cachen), afvises den anonyme proeve (fail-closed).
 *     Foer godkendte `rescue(..., false, false)` opslaget.
 *   - Uden en startet session er kalderen anonym med proeven brugt (M4).
 *     Foer var alle gates aabne uden session.
 *   - `ip_daily_limit` staar i config/metis.php, og koden laeser config.
 */
const FC_EJER = ['data' => ['property' => ['owners' => [['name' => 'EJER HEMMELIGSEN']], 'mortgages' => []]]];
const FC_ADRESSE = 'Travervænget 3, 2920 Charlottenlund';

/**
 * En cache-store hvor KUN IP-taellerens noegler fejler, saa sessionen,
 * throttle-middlewaren og resten af siden virker som i prod.
 *
 * 'skriv' = put/increment/decrement kaster (laesning virker; `Cache::add`
 *           er get + put paa en ArrayStore);
 * 'alt'   = ogsaa get kaster.
 */
class IpTaellerNedeStore extends ArrayStore
{
    public static string $tilstand = 'skriv';

    protected function fejl(string $key, bool $laesning = false): void
    {
        if (str_starts_with($key, 'metis:anon_lookups:ip:') && (! $laesning || self::$tilstand === 'alt')) {
            throw new RuntimeException('cache nede (test)');
        }
    }

    public function get($key)
    {
        $this->fejl($key, laesning: true);

        return parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        $this->fejl($key);

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $this->fejl($key);

        return parent::increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        $this->fejl($key);

        return parent::decrement($key, $value);
    }
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    config()->set('metis.gating.free_lookups', 1);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*/v1/property/analysis' => Http::response(FC_EJER),
        '*' => Http::response(['data' => []]),
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.88']);
});

function ipTaellerNede(string $tilstand): void
{
    IpTaellerNedeStore::$tilstand = $tilstand;
    Cache::extend('ip-taeller-nede', fn () => Cache::repository(new IpTaellerNedeStore));
    config()->set('cache.stores.ip-taeller-nede', ['driver' => 'ip-taeller-nede']);
    config()->set('cache.default', 'ip-taeller-nede');
}

function fcSearchSnapshot(string $html): string
{
    preg_match('/wire:snapshot="([^"]*&quot;name&quot;:&quot;metis-search&quot;[^"]*)"/', $html, $m);

    return html_entity_decode($m[1], ENT_QUOTES);
}

function erAnalyse($r): bool
{
    return str_contains($r->url(), '/v1/property/analysis');
}

// ---------------------------------------------------------------------------
// 3. Fail-closed naar IP-taelleren fejler
// ---------------------------------------------------------------------------

it('🚨 fejler IP-taellerens skrivning, faar en anonym gaten og intet opslag', function (string $tilstand) {
    ipTaellerNede($tilstand);
    Log::spy();

    $this->get('/lookup/address/'.FC_ADRESSE)
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag')
        ->assertDontSee('metis-address-owners', false);

    Http::assertNotSent(fn ($r) => erAnalyse($r));
    expect(app(LookupAccess::class)->erGodkendt('address', FC_ADRESSE))->toBeFalse();
    Log::shouldHaveReceived('error')->withArgs(fn ($besked) => str_contains($besked, 'metis.ip_taeller'))->atLeast()->once();
})->with(['kun skrivning fejler' => 'skriv', 'alt fejler' => 'alt']);

it('🚨 fejler IP-taelleren, giver krydsopslaget heller ingen sektioner', function () {
    ipTaellerNede('skriv');

    $snap = fcSearchSnapshot($this->get('/')->assertOk()->getContent());

    $html = (string) $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snap,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => 'crossReference', 'params' => ['address', FC_ADRESSE]]],
        ]],
    ])->assertOk()->json('components.0.effects.html');

    expect($html)->not->toContain('metis-address-owners');
    Http::assertNotSent(fn ($r) => erAnalyse($r));
});

it('modstykke: en identificeret bruger rammes ikke af en fejlende IP-taeller', function () {
    ipTaellerNede('alt');
    $this->withSession(['metis_verified_email' => 'kunde@firma.dk']);

    $this->get('/lookup/address/'.FC_ADRESSE)
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag')
        ->assertSee('metis-address-owners', false);
});

it('modstykke: virker cachen, faar en anonym sin proeve', function () {
    $this->get('/lookup/address/'.FC_ADRESSE)
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag')
        ->assertSee('metis-address-owners', false);
});

// ---------------------------------------------------------------------------
// 4. Fail-closed uden startet session (M4)
// ---------------------------------------------------------------------------

it('🚨 uden session er kalderen anonym med proeven brugt, i alle praedikater', function () {
    // Vaerdier i session-storen, men sessionen er IKKE startet: de kan ikke
    // komme fra en cookie og maa ikke tælle.
    app('session')->put('metis_verified_email', 'kunde@firma.dk');
    app('session')->put('metis_user_token', '1|aegte');
    app('session')->put('metis_pilot_account_id', 7);

    $adgang = app(LookupAccess::class);
    expect($adgang->harSession())->toBeFalse()
        ->and($adgang->erIdentificeret())->toBeFalse()
        ->and($adgang->erPilot())->toBeFalse()
        ->and($adgang->erKvoteFritaget())->toBeFalse()
        ->and($adgang->anonymtOpslagTilladt())->toBeFalse()
        ->and($adgang->anonymKvoteOverskredet())->toBeTrue()
        ->and($adgang->godkendOpslag('address', FC_ADRESSE))->toBeFalse()
        ->and($adgang->erGodkendt('address', FC_ADRESSE))->toBeFalse()
        ->and($adgang->erGodkendt('cvr', '37792594'))->toBeFalse();
});

it('🚨 uden session giver datalaget hverken person-, CPR-, adresse- eller CVR-data', function () {
    expect(app(LookupAccess::class)->harSession())->toBeFalse();
    $api = app(RegistryApi::class);

    expect($api->fetchCompaniesByCpr('3112781234'))->toBe(['error' => 'login_required', 'status' => 401])
        ->and($api->fetchPersonRoles('Lars Larsen'))->toMatchArray(['error' => 'login_required'])
        ->and($api->resolveAddressAnalysis(FC_ADRESSE))->not->toHaveKey('data');

    expect(fn () => $api->fetchCompany('37792594'))->toThrow(QuotaExceededException::class);
    expect(fn () => $api->fetchCompanyStructuresPooled(['37792594']))->toThrow(QuotaExceededException::class);

    Http::assertNothingSent();
});

it('🚨 uden session henter en sektion mountet direkte intet', function () {
    expect(app(LookupAccess::class)->harSession())->toBeFalse();

    Livewire::test('metis-company-info', ['query' => '37792594']);
    Livewire::test('metis-person-roles', ['query' => 'Lars Larsen']);

    Http::assertNothingSent();
});

it('modstykke: med gating slaaet fra virker datalaget ogsaa uden session', function () {
    config()->set('metis.gating.enabled', false);

    expect(app(LookupAccess::class)->harSession())->toBeFalse();
    app(RegistryApi::class)->fetchCompany('37792594');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/cvr/company/37792594'));
});

it('modstykke: med en startet, identificeret session virker datalaget', function () {
    $this->withSession(['metis_verified_email' => 'kunde@firma.dk']);

    expect(app(LookupAccess::class)->harSession())->toBeTrue();
    app(RegistryApi::class)->fetchCompany('37792594');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/cvr/company/37792594'));
});

// ---------------------------------------------------------------------------
// 5. ip_daily_limit i config/metis.php
// ---------------------------------------------------------------------------

it('ip_daily_limit staar i config/metis.php med standard 5 og METIS_IP_DAILY_LIMIT', function () {
    $fil = require __DIR__.'/../../config/metis.php';
    expect($fil['gating'])->toHaveKey('ip_daily_limit')
        ->and((int) $fil['gating']['ip_daily_limit'])->toBe(5)
        ->and(config('metis.gating.ip_daily_limit'))->toBe(5);

    putenv('METIS_IP_DAILY_LIMIT=3');
    try {
        $fil = require __DIR__.'/../../config/metis.php';
        expect((int) $fil['gating']['ip_daily_limit'])->toBe(3);
    } finally {
        putenv('METIS_IP_DAILY_LIMIT');
    }

    expect(file_get_contents(__DIR__.'/../../src/Services/LookupAccess.php'))
        ->toContain("config('metis.gating.ip_daily_limit')")
        ->not->toContain("config('metis.gating.ip_daily_limit', ");
});

it('🚨 graensen laeses fra config: med 2 faar den tredje session paa IPen gaten', function () {
    config()->set('metis.gating.ip_daily_limit', 2);

    foreach ([1, 2] as $i) {
        $this->flushSession();
        $this->get('/lookup/address/Travervænget '.$i.', 2920 Charlottenlund')
            ->assertOk()->assertDontSee('Du har brugt dine gratis opslag');
    }

    $this->flushSession();
    $this->get('/lookup/address/Travervænget 9, 2920 Charlottenlund')
        ->assertOk()->assertSee('Du har brugt dine gratis opslag');
});

it('🪤 en publiceret vaerts-config uden noeglen faar pakkens standard, ikke 0', function () {
    // Vaerten (metis) har en publiceret config/metis.php, hvis `gating` IKKE
    // har `ip_daily_limit`. `mergeConfigFrom()` fletter kun det oeverste
    // niveau, saa vaertens `gating` ville erstatte pakkens helt.
    $vaert = require __DIR__.'/../../config/metis.php';
    unset($vaert['gating']['ip_daily_limit']);
    config()->set('metis', $vaert);

    (new MetisServiceProvider(app()))->register();

    expect(config('metis.gating.ip_daily_limit'))->toBe(5)
        ->and(app(LookupAccess::class)->ipGraense())->toBe(5);
});

it('modstykke: vaertens egen vaerdi vinder over pakkens', function () {
    $vaert = require __DIR__.'/../../config/metis.php';
    $vaert['gating']['ip_daily_limit'] = 2;
    config()->set('metis', $vaert);

    (new MetisServiceProvider(app()))->register();

    expect(app(LookupAccess::class)->ipGraense())->toBe(2);
});
