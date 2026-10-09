<?php

use TheFountainhead\Metis\Exceptions\CriiptoUnreachableException;
use TheFountainhead\Metis\Auth\SocialiteCriiptoProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/*
 * Circuit breaker: during a sustained outage every login attempt held a PHP-FPM
 * worker ~16s before failing (3 attempts x 5s connect timeout), and some tenant
 * pools have only 6 workers. After three calls in a row have exhausted their
 * retries within 60s, Criipto is not called at all for 30s: logins fail at once
 * with CriiptoUnreachableException, which the callback turns into the MitID
 * "try again" page.
 */
beforeEach(function () {
    config(['services.criipto.base_uri' => 'https://sequii.mitid.dk']);
    Cache::flush();
    Sleep::fake();
    Cache::put(criiptoCacheKey('openid-config'), (object) [
        'token_endpoint' => 'https://sequii.mitid.dk/oauth2/token',
        'jwks_uri' => 'https://sequii.mitid.dk/.well-known/jwks',
    ], 3600);
});

function breakerDnsTimeout(): ConnectException
{
    return new ConnectException(
        'cURL error 28: Resolving timed out after 5001 milliseconds for https://sequii.mitid.dk/oauth2/token',
        new Request('POST', 'https://sequii.mitid.dk/oauth2/token'),
        null,
        ['errno' => 28]
    );
}

function breakerProvider(MockHandler $mock): SocialiteCriiptoProvider
{
    $provider = new SocialiteCriiptoProvider(new HttpRequest, 'client-id', 'client-secret', 'https://example.test/auth/callback');
    $provider->setHttpClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $provider;
}

/** One token exchange whose three attempts all fail on DNS. */
function breakerExhaustedCall(): void
{
    $mock = new MockHandler([breakerDnsTimeout(), breakerDnsTimeout(), breakerDnsTimeout()]);

    try {
        breakerProvider($mock)->getAccessTokenResponse('code');
    } catch (ConnectException|CriiptoUnreachableException) {
    }
}

it('opens after three exhausted calls and then fails without calling Criipto', function () {
    breakerExhaustedCall();
    breakerExhaustedCall();
    breakerExhaustedCall();

    $mock = new MockHandler([new Response(200, [], json_encode(['id_token' => 'x']))]);

    expect(fn () => breakerProvider($mock)->getAccessTokenResponse('code'))
        ->toThrow(CriiptoUnreachableException::class);
    expect($mock->count())->toBe(1); // nothing was sent
});

it('stays closed after two exhausted calls', function () {
    breakerExhaustedCall();
    breakerExhaustedCall();

    $mock = new MockHandler([new Response(200, [], json_encode(['id_token' => 'x']))]);

    expect(breakerProvider($mock)->getAccessTokenResponse('code')['id_token'])->toBe('x');
});

it('a successful call resets the count', function () {
    breakerExhaustedCall();
    breakerExhaustedCall();
    breakerProvider(new MockHandler([new Response(200, [], json_encode(['id_token' => 'x']))]))->getAccessTokenResponse('code');
    breakerExhaustedCall();
    breakerExhaustedCall();

    $mock = new MockHandler([new Response(200, [], json_encode(['id_token' => 'y']))]);

    expect(breakerProvider($mock)->getAccessTokenResponse('code')['id_token'])->toBe('y');
});

it('a retry that recovers does not count as a failure', function () {
    breakerExhaustedCall();
    breakerExhaustedCall();
    // One lost DNS answer, then success: the call worked, so it is not a failure.
    breakerProvider(new MockHandler([breakerDnsTimeout(), new Response(200, [], json_encode(['id_token' => 'x']))]))->getAccessTokenResponse('code');

    $mock = new MockHandler([new Response(200, [], json_encode(['id_token' => 'y']))]);

    expect(breakerProvider($mock)->getAccessTokenResponse('code')['id_token'])->toBe('y');
});

it('lets the next call through once the 30 seconds have passed', function () {
    breakerExhaustedCall();
    breakerExhaustedCall();
    breakerExhaustedCall();

    $this->travel(31)->seconds();

    $mock = new MockHandler([new Response(200, [], json_encode(['id_token' => 'x']))]);

    expect(breakerProvider($mock)->getAccessTokenResponse('code')['id_token'])->toBe('x');
});

it('is still open just before the 30 seconds have passed', function () {
    breakerExhaustedCall();
    breakerExhaustedCall();
    breakerExhaustedCall();

    $this->travel(29)->seconds();

    $mock = new MockHandler([new Response(200, [], json_encode(['id_token' => 'x']))]);

    expect(fn () => breakerProvider($mock)->getAccessTokenResponse('code'))
        ->toThrow(CriiptoUnreachableException::class);
});

it('forgets failures older than 60 seconds', function () {
    breakerExhaustedCall();
    breakerExhaustedCall();

    $this->travel(61)->seconds();
    breakerExhaustedCall();

    $mock = new MockHandler([new Response(200, [], json_encode(['id_token' => 'x']))]);

    expect(breakerProvider($mock)->getAccessTokenResponse('code')['id_token'])->toBe('x');
});

it('logs an error once when it opens, so a sustained outage stays visible', function () {
    Log::spy();

    breakerExhaustedCall();
    breakerExhaustedCall();
    breakerExhaustedCall();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn ($message) => str_contains($message, 'Criipto circuit breaker opened'));
});
