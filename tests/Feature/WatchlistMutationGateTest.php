<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\FollowButton;
use TheFountainhead\Metis\Livewire\PersonFollowButton;

/**
 * Fix-runde 1, review V3: en anonym maa ikke aendre tenantens watchlists og
 * alerts. Uden token koerer kaldene paa den DELTE tenant-noegle, og
 * metoderne er offentlige over `/livewire/update`.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []])]);
});

function erMutation($r): bool
{
    return in_array($r->method(), ['POST', 'DELETE', 'PATCH'], true)
        && (str_contains($r->url(), '/v1/watchlists') || str_contains($r->url(), '/v1/alerts'))
        && ! str_contains($r->url(), 'check-batch');
}

function alertsSnapshot(object $test): string
{
    $html = $test->get('/alerts')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]*&quot;name&quot;:&quot;metis-alerts-inbox&quot;[^"]*)"/', $html, $m);

    return html_entity_decode($m[1], ENT_QUOTES);
}

function kald(object $test, string $snapshot, string $metode, array $params)
{
    return $test->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => $metode, 'params' => $params]],
        ]],
    ]);
}

it('🚨 en anonym kan ikke slette en watchlist via /alerts (unfollow over /livewire/update)', function () {
    kald($this, alertsSnapshot($this), 'unfollow', [5])->assertOk();

    Http::assertNotSent(fn ($r) => erMutation($r));
});

it('🚨 en anonym kan ikke markere en alert som laest via /alerts', function () {
    kald($this, alertsSnapshot($this), 'markRead', [5])->assertOk();

    Http::assertNotSent(fn ($r) => erMutation($r));
});

it('🚨 et opdigtet token (setToken-formatet) kan heller ikke slette', function () {
    $this->withSession(['metis_user_token' => '1|x']);

    kald($this, alertsSnapshot($this), 'unfollow', [5])->assertOk();

    Http::assertNotSent(fn ($r) => erMutation($r));
});

it('🚨 en verificeret mail uden pilot kan ikke skrive paa den delte noegle', function () {
    $this->withSession(['metis_verified_email' => 'kunde@firma.dk']);

    Livewire::test(FollowButton::class, ['watchType' => 'property', 'watchValue' => '123'])
        ->call('toggle')
        ->assertSet('isFollowed', false);

    Http::assertNotSent(fn ($r) => erMutation($r));
});

it('🚨 en anonym kan ikke oprette en watchlist via FollowButton eller PersonFollowButton', function () {
    Livewire::test(FollowButton::class, ['watchType' => 'company', 'watchValue' => '37792594'])->call('toggle');
    Livewire::test(PersonFollowButton::class, ['name' => 'Lars Larsen'])->call('follow', '4000123');

    Http::assertNotSent(fn ($r) => erMutation($r));
});

it('modstykke: en bekraeftet pilot kan slette sin watchlist', function () {
    $this->withSession(['metis_user_token' => '1|ægte', 'metis_pilot_account_id' => 7, 'metis_verified_email' => 'pilot@frankston.io']);

    kald($this, alertsSnapshot($this), 'unfollow', [5])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/v1/watchlists/5'));
});
