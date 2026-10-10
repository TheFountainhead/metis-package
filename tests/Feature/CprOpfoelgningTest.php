<?php

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\FollowButton;

/**
 * Opfoelgning paa CPR-lukningen (metis-package#195, review 10/10-2026).
 *
 * 1. Admin-login gemte admin'ens raa CPR i sessionen (`metis_admin_cpr`).
 *    Intet laeste den, men Flare sender hele sessionen med en fejlrapport.
 * 2. FollowButtons watchType/watchValue var ikke laaste, saa en klient kunne
 *    saette typen til `cpr` og gemme et CPR som watchlist-raekke.
 */
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
