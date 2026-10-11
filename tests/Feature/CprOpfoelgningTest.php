<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\AlertsInbox;
use TheFountainhead\Metis\Livewire\FollowButton;

/**
 * Opfoelgning paa CPR-lukningen (metis-package#195, review 10/10-2026).
 *
 * 1. Admin-login gemte admin'ens raa CPR i sessionen (`metis_admin_cpr`).
 *    Intet laeste den, men Flare sender hele sessionen med en fejlrapport.
 * 2. FollowButtons watchType/watchValue var ikke laaste, saa en klient kunne
 *    saette typen til `cpr` og gemme et CPR som watchlist-raekke.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->app->register(\Laravel\Socialite\SocialiteServiceProvider::class));

it('gemmer ikke admin-CPR i sessionen efter MitID-login', function () {
    config()->set('metis.admin.allowed_cprs', '0101011234');
    $user = (new SocialiteUser)->setRaw(['cprNumberIdentifier' => '010101-1234'])->map(['id' => 'sub-1']);
    Socialite::shouldReceive('driver')->with('criipto')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($user);

    $this->get('/admin/auth/callback?code=abc&state=xyz')
        ->assertRedirect(route('metis.admin.dashboard'));

    expect(session('metis_admin_authenticated'))->toBeTrue()
        ->and(session()->has('metis_admin_cpr'))->toBeFalse()
        ->and(json_encode(session()->all()))->not->toContain('0101011234');
});

it('laaser FollowButtons watch-type og -vaerdi', function (string $felt) {
    Http::fake(['*' => Http::response(['data' => []])]);

    Livewire::test(FollowButton::class, ['watchType' => 'company', 'watchValue' => '35050027'])
        ->set($felt, 'cpr');
})->with(['watchType', 'watchValue'])->throws(CannotUpdateLockedPropertyException::class);

/**
 * Planter en session hos klienten via cookie, som en angriber ville, og
 * returnerer dens ID. Uden `regenerate()` fortsaetter requesten paa PRAECIS
 * dette ID, saa "ID'et skiftede" kan kun vaere sandt, hvis koden skifter det.
 */
function plantSession(array $data): string
{
    $id = str_repeat('a', 40);
    app('session')->driver()->getHandler()->write($id, serialize($data));

    return $id;
}

it('udsteder et nyt session-id ved admin-login (session fixation)', function () {
    config()->set('metis.admin.allowed_cprs', '0101011234');
    $user = (new SocialiteUser)->setRaw(['cprNumberIdentifier' => '0101011234'])->map(['id' => 'sub-1']);
    Socialite::shouldReceive('driver')->with('criipto')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($user);

    $plantet = plantSession(['_token' => 'x']);

    $this->withCookie(config('session.cookie'), $plantet)
        ->get('/admin/auth/callback?code=abc&state=xyz')
        ->assertRedirect(route('metis.admin.dashboard'));

    expect(session()->getId())->not->toBe($plantet)
        ->and(session('metis_admin_authenticated'))->toBeTrue();
});

it('udsteder et nyt session-id ved admin-logout og bevarer resten af sessionen', function () {
    $foer = plantSession(['_token' => 'x', 'metis_admin_authenticated' => true, 'metis_verified_email' => 'a@b.dk']);

    $this->withCookie(config('session.cookie'), $foer)
        ->post('/admin/logout')
        ->assertRedirect(route('metis.admin.login'));

    // Den GAMLE raekke maa ikke stadig give admin: ellers virker en stjaalet
    // eller plantet cookie efter logout (review #197 P1).
    $gammel = app('session')->driver()->getHandler()->read($foer);

    expect(session()->getId())->not->toBe($foer)
        ->and($gammel)->not->toContain('metis_admin_authenticated')
        ->and(session()->has('metis_admin_authenticated'))->toBeFalse()
        ->and(session('metis_verified_email'))->toBe('a@b.dk');
});

it('sletter den gamle session-raekke ved pilot-logout', function () {
    $foer = plantSession(['_token' => 'x', 'metis_user_token' => 'hemmelig-token', 'metis_verified_email' => 'a@b.dk']);

    $this->withCookie(config('session.cookie'), $foer)
        ->post('/log-ud')
        ->assertRedirect(route('metis.home'));

    expect(app('session')->driver()->getHandler()->read($foer))->not->toContain('hemmelig-token')
        ->and(session()->getId())->not->toBe($foer)
        ->and(session()->has('metis_user_token'))->toBeFalse();
});

it('genindlaeser alerts-siden efter pilot-logout, saa den ikke sidder med et udloebet CSRF-token', function () {
    // logout() roterer CSRF-tokenet (regenerate). Bliver siden staaende, gaar
    // naeste handling (fx setToken) ud med det gamle token og faar 419.
    config()->set('metis.mode', 'standalone');
    Http::fake(['*' => Http::response(['data' => []])]);
    $this->withSession(['metis_user_token' => '1|aegte', 'metis_pilot_account_id' => 7, 'metis_verified_email' => 'pilot@frankston.io']);

    Livewire::test(AlertsInbox::class)
        ->call('clearToken')
        ->assertRedirect(route('metis.alerts'));
});
