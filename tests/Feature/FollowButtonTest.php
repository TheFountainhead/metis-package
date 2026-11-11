<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\FollowButton;

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    Http::preventStrayRequests();
});

function followPilot(): array
{
    return ['metis_user_token' => '19|abc', 'metis_pilot_verificeret' => true];
}

it('selskabssiden viser Følg-knappen for en bekræftet pilot', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->withSession(followPilot())->get('/lookup/cvr/41867132')
        ->assertOk()
        ->assertSee('metis-follow-button', false);
});

it('selskabssiden viser IKKE Følg-knappen uden bekræftet pilot', function (array $session) {
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->withSession($session)->get('/lookup/cvr/41867132')
        ->assertOk()
        ->assertDontSee('metis-follow-button', false);
})->with([
    'anonym' => [[]],
    'token uden bekræftelse' => [['metis_user_token' => '19|abc']],
]);

it('Følg opretter en virksomheds-watch på brugerens token', function () {
    session()->start();
    session(followPilot());
    Http::fake([
        '*/v1/watchlists/check-batch' => Http::response(['data' => [['is_followed' => false, 'watchlist_id' => null]]]),
        '*/v1/watchlists' => Http::response(['data' => ['id' => 351]], 201),
    ]);

    Livewire::test(FollowButton::class, ['watchType' => 'company', 'watchValue' => '41867132'])
        ->call('toggle')
        ->assertSet('isFollowed', true)
        ->assertSet('watchlistId', 351);

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/watchlists')
        && $r['watch_type'] === 'company' && $r['watch_value'] === '41867132' && $r->hasHeader('Authorization', 'Bearer 19|abc'));
});

it('en afvist sletning beholder "Følger" og viser fejl (ikke falsk "Følg")', function () {
    session()->start();
    session(followPilot());
    Http::fake([
        '*/v1/watchlists/check-batch' => Http::response(['data' => [['is_followed' => true, 'watchlist_id' => 351]]]),
        '*/v1/watchlists/351' => Http::response(['message' => 'Forbidden'], 403),
    ]);

    Livewire::test(FollowButton::class, ['watchType' => 'company', 'watchValue' => '41867132'])
        ->assertSet('isFollowed', true)
        ->call('toggle')
        ->assertSet('isFollowed', true)
        ->assertSet('watchlistId', 351)
        ->assertSee('Kunne ikke fjerne fra watchlist');
});
