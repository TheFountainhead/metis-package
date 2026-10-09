<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use TheFountainhead\Metis\Exceptions\CriiptoUnreachableException;

/*
 * The admin MitID callback had no error handling: a DNS timeout against
 * Criipto (8 Oct 2026) became a 500, and the raw exception reached Flare with
 * the OAuth code and client_secret in its frames. Now the admin is sent back
 * to the login page with a message, and only the message is logged.
 */
// Testbench boots without Socialite; the app that installs the package has it.
beforeEach(fn () => $this->app->register(\Laravel\Socialite\SocialiteServiceProvider::class));

dataset('unreachable', [
    'DNS timeout on token exchange' => fn () => new ConnectException('cURL error 28: Resolving timed out after 5001 milliseconds for https://sequii.mitid.dk/oauth2/token', new Request('POST', 'https://sequii.mitid.dk/oauth2/token')),
    'Criipto 5xx' => fn () => new ServerException('Server error: 503', new Request('POST', 'https://sequii.mitid.dk/oauth2/token'), new Response(503)),
    'discovery unreachable / breaker open' => fn () => new CriiptoUnreachableException('Unable to connect to the Criipto identity provider'),
]);

it('callback sends the admin back to login with a message instead of a 500', function (Throwable $e) {
    Socialite::shouldReceive('driver')->with('criipto')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow($e);
    Log::spy();

    $this->get('/admin/auth/callback?code=abc&state=xyz')
        ->assertRedirect(route('metis.admin.login'))
        ->assertSessionHas('error', 'MitID kan ikke nås lige nu. Prøv igen om et øjeblik.');

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn ($message, $context = []) => $message === 'MitID identity provider unreachable during callback'
            && is_string($context['message'] ?? null)
    );
    expect(session('metis_admin_authenticated'))->toBeNull();
})->with('unreachable');

it('redirect sends the admin back to login when Criipto is unreachable', function (Throwable $e) {
    Socialite::shouldReceive('driver')->with('criipto')->andReturnSelf();
    Socialite::shouldReceive('redirect')->andThrow($e);
    Log::spy();

    $this->get('/admin/auth/redirect')
        ->assertRedirect(route('metis.admin.login'))
        ->assertSessionHas('error');

    Log::shouldHaveReceived('error')->once();
})->with('unreachable');
