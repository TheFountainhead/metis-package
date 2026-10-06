<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\FollowButton;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Opfoelgning paa PR #192 (re-review, opfoelgning 1): LAESNING af alerts og
 * watchlists kraever en bekraeftet pilot, ligesom mutationerne.
 *
 * Uden token koerer `client()` paa den DELTE tenant-noegle, saa en anonym
 * kunne laese Frankstons watchlists og alerts: `GET /alerts/{id}` og
 * `AlertsInbox::fetch()`/`loadWatchlists()`, som er offentlige over
 * `/livewire/update` uanset hvad siden viser.
 *
 * Desuden (opfoelgning 2): `/alerts` og `/alerts/{id}` gav 500, naar svaret
 * fra registry-api ikke havde den forventede form.
 */
const ALERT_SVAR = ['data' => [[
    'id' => 5,
    'title' => 'HEMMELIG ALERT',
    'description' => 'Bredgade 40, 1.250.000 kr.',
    'is_read' => false,
    'priority' => 'high',
    'created_at' => '2026-10-01T10:00:00Z',
    'watchlist' => ['watch_type' => 'property', 'display_label' => 'HEMMELIG EJENDOM'],
    'metadata' => [],
]], 'current_page' => 1, 'last_page' => 1];

const WATCHLIST_SVAR = ['data' => [[
    'id' => 9, 'watch_type' => 'property', 'watch_value' => '123', 'display_label' => 'HEMMELIG WATCH',
]]];

const ENKELT_ALERT_SVAR = ['data' => [
    'id' => 5,
    'title' => 'HEMMELIG ALERT',
    'description' => 'Bredgade 40',
    'is_read' => false,
    'priority' => 'high',
    'created_at' => '2026-10-01T10:00:00Z',
    'metadata' => ['change_kind' => 'new', 'address' => 'Bredgade 40, 1260 København K'],
]];

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    Cache::flush();
    Http::preventStrayRequests();
});

/**
 * 🪤 Ikke i beforeEach: `Http::fake()` LAEGGER stubs til (foerste match
 * vinder), saa testene med en uventet svarform ville ellers faa disse.
 */
function fakeAlertsOk(): void
{
    Http::fake([
        '*/v1/alerts/5' => Http::response(ENKELT_ALERT_SVAR),
        '*/v1/alerts*' => Http::response(ALERT_SVAR),
        '*/v1/watchlists/check-batch' => Http::response(['data' => [['is_followed' => true, 'watchlist_id' => 9]]]),
        '*/v1/watchlists*' => Http::response(WATCHLIST_SVAR),
        '*' => Http::response(['data' => []]),
    ]);
}

function erAlertLaesning($r): bool
{
    return str_contains($r->url(), '/v1/alerts') || str_contains($r->url(), '/v1/watchlists');
}

function inboxSnapshot(object $test): string
{
    $html = $test->get('/alerts')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]*&quot;name&quot;:&quot;metis-alerts-inbox&quot;[^"]*)"/', $html, $m);

    return html_entity_decode($m[1], ENT_QUOTES);
}

function detailSnapshot(string $html): string
{
    // AlertDetail er ikke registreret med et navn; Livewire afleder det af klassen.
    preg_match('/wire:snapshot="([^"]*&quot;name&quot;:&quot;[^&]*alert-detail&quot;[^"]*)"/', $html, $m);

    expect($m)->toHaveKey(1);

    return html_entity_decode($m[1], ENT_QUOTES);
}

function alertKald(object $test, string $snapshot, string $metode, array $params = [], array $updates = [])
{
    return $test->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) $updates,
            'calls' => [['path' => '', 'method' => $metode, 'params' => $params]],
        ]],
    ]);
}

function pilotSession(object $test): void
{
    $test->withSession(['metis_user_token' => '1|aegte', 'metis_pilot_account_id' => 7, 'metis_verified_email' => 'pilot@frankston.io']);
}

dataset('ikke-piloter', [
    'anonym' => [[]],
    'kun verificeret mail' => [['metis_verified_email' => 'kunde@firma.dk']],
    'uproevet token (setToken-formatet)' => [['metis_user_token' => '1|x']],
    'verificeret mail + uproevet token' => [['metis_verified_email' => 'kunde@firma.dk', 'metis_user_token' => '1|x']],
]);

// ---------------------------------------------------------------------------
// 1. Laesninger kraever pilot, over HTTP
// ---------------------------------------------------------------------------

it('🚨 en ikke-pilot faar INTET fra AlertsInbox::fetch og loadWatchlists over /livewire/update', function (array $session) {
    fakeAlertsOk();

    if ($session !== []) {
        $this->withSession($session);
    }

    $snap = inboxSnapshot($this);

    foreach (['fetch', 'loadWatchlists'] as $metode) {
        $svar = alertKald($this, $snap, $metode)->assertOk();
        $data = json_decode($svar->json('components.0.snapshot'), true)['data'];

        expect($svar->getContent())->not->toContain('HEMMELIG')
            // Tom tilstand, ikke en fejl: komponenten spoerger slet ikke.
            ->and($data['error'])->toBeNull();
    }

    Http::assertNotSent(fn ($r) => erAlertLaesning($r));
})->with('ikke-piloter');

it('🚨 en ikke-pilot faar INTET fra GET /alerts/{id} og AlertDetail::fetch', function (array $session) {
    fakeAlertsOk();

    if ($session !== []) {
        $this->withSession($session);
    }

    $html = $this->get('/alerts/5')->assertOk()->assertDontSee('HEMMELIG')->getContent();

    alertKald($this, detailSnapshot($html), 'fetch')->assertOk()->assertDontSee('HEMMELIG');

    Http::assertNotSent(fn ($r) => erAlertLaesning($r));
})->with('ikke-piloter');

it('🚨 en ikke-pilot faar ingen follow-status fra den delte noegle (checkBatch)', function (array $session) {
    fakeAlertsOk();

    if ($session !== []) {
        $this->withSession($session);
    }

    Livewire::test(FollowButton::class, ['watchType' => 'property', 'watchValue' => '123'])
        ->assertSet('isFollowed', false)
        ->assertSet('watchlistId', null);

    Http::assertNotSent(fn ($r) => erAlertLaesning($r));
})->with('ikke-piloter');

it('🚨 datalaget: laesningerne afviser en ikke-pilot uden et HTTP-kald', function () {
    fakeAlertsOk();
    $this->withSession(['metis_verified_email' => 'kunde@firma.dk', 'metis_user_token' => '1|x']);
    $api = app(RegistryApi::class);

    expect($api->listAlerts())->toBe(['error' => 'pilot_required', 'status' => 403])
        ->and($api->listWatchlists())->toBe(['error' => 'pilot_required', 'status' => 403])
        ->and($api->checkBatch([['type' => 'property', 'value' => '123']]))->toBe(['error' => 'pilot_required', 'status' => 403])
        ->and($api->getAlert(5))->toBeNull();

    Http::assertNothingSent();
});

it('🚨 en ikke-pilot med token ser en besked om login, ikke "du foelger ikke noget"', function () {
    fakeAlertsOk();
    $this->withSession(['metis_user_token' => '1|x']);

    $this->get('/alerts')->assertOk()
        ->assertSee('Log ind med din pilotkonto')
        ->assertDontSee('Du følger ikke noget endnu');
});

it('modstykke: en bekraeftet pilot ser sine alerts, watchlists og alert-detaljen', function () {
    fakeAlertsOk();
    pilotSession($this);

    // 🪤 FOER side-requestene: en request gemmer sessionen og efterlader den
    // IKKE-startet, og uden startet session er man anonym (M4).
    Livewire::test(FollowButton::class, ['watchType' => 'property', 'watchValue' => '123'])
        ->assertSet('isFollowed', true);

    $this->get('/alerts')->assertOk()->assertSee('HEMMELIG ALERT');

    $svar = alertKald($this, inboxSnapshot($this), 'toggleWatchlists')->assertOk();
    expect($svar->getContent())->toContain('HEMMELIG WATCH');

    $this->get('/alerts/5')->assertOk()->assertSee('HEMMELIG ALERT');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/alerts'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/watchlists'));
});

it('🐛 modstykke: en pilot kan markere en alert som laest fra /alerts/{id} over /livewire/update', function () {
    fakeAlertsOk();
    pilotSession($this);

    $html = $this->get('/alerts/5')->assertOk()->assertSee('HEMMELIG ALERT')->getContent();

    alertKald($this, detailSnapshot($html), 'markRead')->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/v1/alerts/5/read'));
});

it('modstykke: en pilot via EmailGate (metis_pilot_verificeret) ser ogsaa sine alerts', function () {
    fakeAlertsOk();
    $this->withSession(['metis_user_token' => '1|aegte', 'metis_pilot_verificeret' => true, 'metis_verified_email' => 'pilot@frankston.io']);

    $this->get('/alerts')->assertOk()->assertSee('HEMMELIG ALERT');
});

// ---------------------------------------------------------------------------
// 2. Uventet svarform giver ikke 500
// ---------------------------------------------------------------------------

dataset('uventede svar', [
    'alert uden felter' => [['data' => [['id' => 1]]]],
    'data er en streng' => [['data' => 'x']],
    'data er en liste af tal' => [['data' => [1, 2]]],
    'felter med forkert type' => [['data' => [[
        'id' => 1, 'title' => ['x'], 'description' => ['y'], 'is_read' => 'nej', 'priority' => ['high'],
        'created_at' => 'ikke en dato', 'watchlist' => 'x', 'metadata' => 7,
    ]], 'current_page' => 'a', 'last_page' => ['b']]],
    'watchlist-raekker uden felter' => [['data' => [['foo' => 1], 'x', ['id' => 3]]]],
    'metadata med array-prioritet' => [['data' => [['id' => 1, 'metadata' => ['priority' => ['high'], 'change_kind' => ['x']]]]]],
    'diff med tekst-beloeb og before/after af forkert type' => [['data' => [['id' => 1, 'metadata' => [
        'change_type' => 'principal_change', 'before' => ['principal_amount' => 'x', 'creditor' => ['y']], 'after' => 'z',
    ]]]]],
    'metadata som ugyldig json-streng' => [['data' => [['id' => 1, 'metadata' => '{ikke json']]]],
    'tomt svar' => [[]],
]);

it('🐛 /alerts og /alerts/{id} giver ikke 500 paa en uventet svarform', function (array $svar) {
    pilotSession($this);
    Http::fake(['*' => Http::response($svar)]);

    $this->get('/alerts')->assertOk();
    $this->get('/alerts/1')->assertOk();
})->with('uventede svar');

it('🐛 en alert-raekke uden id droppes, de gyldige vises stadig', function () {
    pilotSession($this);
    Http::fake(['*' => Http::response(['data' => [
        ['title' => 'UDEN ID'],
        ['id' => 2, 'title' => 'GYLDIG ALERT'],
    ]])]);

    $this->get('/alerts')->assertOk()->assertSee('GYLDIG ALERT')->assertDontSee('UDEN ID');
});
