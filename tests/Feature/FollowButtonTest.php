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

function followTest(array $fake)
{
    session()->start();
    session(followPilot());
    Http::fake($fake);

    return Livewire::test(FollowButton::class, ['watchType' => 'company', 'watchValue' => '41867132']);
}

it('mount sender intet; status hentes først af loadStatus (siden blokeres ikke)', function () {
    followTest(['*' => Http::response(['data' => []])])->assertSet('isFollowed', null);

    Http::assertNothingSent();
});

it('Følg opretter en virksomheds-watch på brugerens token', function () {
    followTest([
        '*/v1/watchlists/check-batch' => Http::response(['data' => [['is_followed' => false, 'watchlist_id' => null]]]),
        '*/v1/watchlists' => Http::response(['data' => ['id' => 351]], 201),
    ])->call('loadStatus')->assertSet('isFollowed', false)
        ->call('toggle')->assertSet('isFollowed', true)->assertSet('watchlistId', 351);

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/watchlists')
        && $r['watch_type'] === 'company' && $r['watch_value'] === '41867132' && $r->hasHeader('Authorization', 'Bearer 19|abc'));
});

it('et fejlet statusopslag er UKENDT: Følg opretter ingen dublet', function ($svar) {
    $t = followTest([
        '*/v1/watchlists/check-batch' => $svar,
        '*/v1/watchlists' => Http::response(['data' => ['id' => 999]], 201),
    ])->call('loadStatus')->assertSet('isFollowed', null)
        ->call('toggle')->assertSet('isFollowed', null)->assertSee('Kunne ikke hente følge-status');

    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/watchlists'));
})->with([
    '500' => fn () => Http::response('', 500),
    'fulgt uden id' => fn () => Http::response(['data' => [['is_followed' => true, 'watchlist_id' => null]]]),
]);

it('en afvist sletning beholder "Følger" og viser fejl (ikke falsk "Følg")', function () {
    followTest([
        '*/v1/watchlists/check-batch' => Http::response(['data' => [['is_followed' => true, 'watchlist_id' => 351]]]),
        '*/v1/watchlists/351' => Http::response(['message' => 'Forbidden'], 403),
    ])->call('loadStatus')->assertSet('isFollowed', true)
        ->call('toggle')->assertSet('isFollowed', true)->assertSet('watchlistId', 351)
        ->assertSee('Kunne ikke fjerne fra watchlist');
});

it('404 ved sletning = allerede afmeldt (fx i en anden fane), ikke en fast "Følger"', function () {
    followTest([
        '*/v1/watchlists/check-batch' => Http::response(['data' => [['is_followed' => true, 'watchlist_id' => 351]]]),
        '*/v1/watchlists/351' => Http::response(['message' => 'Not found'], 404),
    ])->call('loadStatus')->call('toggle')->assertSet('isFollowed', false)->assertSet('watchlistId', null);
});

it('CVR normaliseres til 8 cifre; ugyldigt CVR giver ingen knap', function (string $sti, ?string $forventet) {
    Http::fake(['*' => Http::response(['data' => []])]);

    $svar = $this->withSession(followPilot())->get($sti)->assertOk();

    $forventet === null
        ? $svar->assertDontSee('metis-follow-button', false)
        : $svar->assertSee('metis-follow-button', false)->assertSee($forventet, false);
})->with([
    'DK-præfiks' => ['/lookup/cvr/DK41867132', '41867132'],
    'for kort' => ['/lookup/cvr/4186713', null],
]);

it('knappen og "Overvågede selskaber" bruger samme regel: embedded uden pilot-flag men med token', function () {
    config()->set('metis.gating.enabled', false);
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->withSession(['metis_user_token' => '19|abc'])->get('/lookup/cvr/41867132')
        ->assertOk()->assertSee('metis-follow-button', false);
    expect(app(\TheFountainhead\Metis\Services\LookupAccess::class)->kanFoelge())->toBeTrue();
});
