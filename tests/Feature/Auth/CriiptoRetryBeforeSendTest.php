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
use Illuminate\Support\Sleep;

/*
 * Ported from Frankston-master #2176 (via faktorkredit #231). Hetzner's resolvers dropped ~27% of
 * uncached lookups on 8 Oct 2026; one lost DNS answer failed a MitID login.
 * The token exchange spends a single-use OAuth code, so it may only be retried
 * when curl provably never sent it.
 */
/** Cache keys are scoped to the Criipto base URI (see cacheKey()). */
function criiptoCacheKey(string $name): string
{
    return "criipto:{$name}:".md5('https://sequii.mitid.dk');
}

beforeEach(function () {
    config(['services.criipto.base_uri' => 'https://sequii.mitid.dk']);
    Cache::flush();
    Sleep::fake();
});

function retryTimeout(string $message, string $method = 'POST', string $url = 'https://sequii.mitid.dk/oauth2/token', int $errno = 28): ConnectException
{
    return new ConnectException("cURL error {$errno}: {$message} for {$url}", new Request($method, $url), null, ['errno' => $errno]);
}

function retryProvider(MockHandler $mock): SocialiteCriiptoProvider
{
    $provider = new SocialiteCriiptoProvider(new HttpRequest, 'client-id', 'client-secret', 'https://example.test/auth/callback');
    $provider->setHttpClient(new Client(['handler' => HandlerStack::create($mock)]));

    return $provider;
}

function retryPrimeDiscovery(): void
{
    Cache::put(criiptoCacheKey('openid-config'), (object) [
        'token_endpoint' => 'https://sequii.mitid.dk/oauth2/token',
        'jwks_uri' => 'https://sequii.mitid.dk/.well-known/jwks',
        'id_token_signing_alg_values_supported' => ['RS256'],
    ], 3600);
}

function retryTokenOk(): Response
{
    return new Response(200, [], json_encode(['id_token' => 'header.payload.sig']));
}

it('retries the token exchange when DNS resolution timed out before sending', function () {
    retryPrimeDiscovery();
    $mock = new MockHandler([retryTimeout('Resolving timed out after 5001 milliseconds'), retryTokenOk()]);

    expect(retryProvider($mock)->getAccessTokenResponse('code')['id_token'])->toBe('header.payload.sig');
    expect($mock->count())->toBe(0);
});

it('retries the token exchange on a TCP connect timeout (libcurl 8.5 wording)', function () {
    retryPrimeDiscovery();
    $mock = new MockHandler([retryTimeout('Failed to connect to sequii.mitid.dk port 443 after 5002 ms: Timeout was reached'), retryTokenOk()]);

    expect(retryProvider($mock)->getAccessTokenResponse('code')['id_token'])->toBe('header.payload.sig');
});

it('retries the token exchange when the host could not be resolved', function () {
    retryPrimeDiscovery();
    $mock = new MockHandler([retryTimeout('Could not resolve host: sequii.mitid.dk', errno: 6), retryTokenOk()]);

    expect(retryProvider($mock)->getAccessTokenResponse('code')['id_token'])->toBe('header.payload.sig');
});

it('never re-sends the token exchange after a timeout once the request was sent', function () {
    retryPrimeDiscovery();
    $mock = new MockHandler([retryTimeout('Operation timed out after 15001 milliseconds with 0 bytes received'), retryTokenOk()]);

    expect(fn () => retryProvider($mock)->getAccessTokenResponse('code'))->toThrow(ConnectException::class);
    expect($mock->count())->toBe(1); // the single-use code went out once
});

it('does not retry a ConnectException without a curl errno', function () {
    retryPrimeDiscovery();
    $mock = new MockHandler([new ConnectException('Connection refused', new Request('POST', 'https://sequii.mitid.dk/oauth2/token')), retryTokenOk()]);

    expect(fn () => retryProvider($mock)->getAccessTokenResponse('code'))->toThrow(ConnectException::class);
    expect($mock->count())->toBe(1);
});

it('gives up after three attempts with two 500ms pauses', function () {
    retryPrimeDiscovery();
    $t = fn () => retryTimeout('Resolving timed out after 5001 milliseconds');
    $mock = new MockHandler([$t(), $t(), $t(), retryTokenOk()]);

    expect(fn () => retryProvider($mock)->getAccessTokenResponse('code'))->toThrow(ConnectException::class);
    expect($mock->count())->toBe(1);
    Sleep::assertSequence([Sleep::usleep(500_000), Sleep::usleep(500_000)]);
});

it('sets connect and total timeouts on the token exchange', function () {
    retryPrimeDiscovery();
    $mock = new MockHandler([retryTokenOk()]);
    retryProvider($mock)->getAccessTokenResponse('code');

    expect($mock->getLastOptions())->toMatchArray(['connect_timeout' => 5, 'timeout' => 15]);
});

it('discovery survives two failed lookups and is cached', function () {
    $url = 'https://sequii.mitid.dk/.well-known/openid-configuration';
    $mock = new MockHandler([
        retryTimeout('Resolving timed out after 5001 milliseconds', 'GET', $url),
        retryTimeout('Resolving timed out after 5001 milliseconds', 'GET', $url),
        new Response(200, [], json_encode(['token_endpoint' => 'https://sequii.mitid.dk/oauth2/token'])),
    ]);
    $provider = retryProvider($mock);
    $method = (new ReflectionClass($provider))->getMethod('getOpenIdConfiguration');

    expect($method->invoke($provider)->token_endpoint)->toBe('https://sequii.mitid.dk/oauth2/token');
    expect(Cache::get(criiptoCacheKey('openid-config'))->token_endpoint)->toBe('https://sequii.mitid.dk/oauth2/token');
});

it('wraps an unreachable discovery in CriiptoUnreachableException', function () {
    $url = 'https://sequii.mitid.dk/.well-known/openid-configuration';
    $t = fn () => retryTimeout('Resolving timed out after 5001 milliseconds', 'GET', $url);
    $provider = retryProvider(new MockHandler([$t(), $t(), $t()]));
    $method = (new ReflectionClass($provider))->getMethod('getOpenIdConfiguration');

    expect(fn () => $method->invoke($provider))->toThrow(CriiptoUnreachableException::class);
});

it('retries and caches the JWKS fetch', function () {
    retryPrimeDiscovery();
    $provider = retryProvider(new MockHandler([
        retryTimeout('Resolving timed out after 5001 milliseconds', 'GET', 'https://sequii.mitid.dk/.well-known/jwks'),
        new Response(200, [], json_encode(['keys' => []])),
    ]));
    $method = (new ReflectionClass($provider))->getMethod('getJWTKeys');

    expect($method->invoke($provider))->toBe([]);
    expect(Cache::get(criiptoCacheKey('jwks')))->toBe(['keys' => []]);
});

/*
 * Review of faktorkredit #231: the JWKS is now cached for an hour. When
 * Criipto rotates its signing key, a token signed with the new key must not
 * fail every login for that hour: an unknown kid refetches the JWKS once.
 */
function rotationKeyPair(string $kid): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

    return [$privatePem, ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]];
}

it('refetches the JWKS once when the token is signed with a key it has not seen', function () {
    retryPrimeDiscovery();
    [, $oldJwk] = rotationKeyPair('old-key');
    [$newPrivate, $newJwk] = rotationKeyPair('new-key');
    Cache::put(criiptoCacheKey('jwks'), ['keys' => [$oldJwk]], 3600); // cached before the rotation

    $token = \Firebase\JWT\JWT::encode(['sub' => 'user-1', 'exp' => time() + 60], $newPrivate, 'RS256', 'new-key');
    $mock = new MockHandler([new Response(200, [], json_encode(['keys' => [$newJwk]]))]);
    $provider = retryProvider($mock);
    $method = (new ReflectionClass($provider))->getMethod('getUserByToken');

    expect($method->invoke($provider, $token)['sub'])->toBe('user-1');
    expect($mock->count())->toBe(0); // exactly one JWKS fetch
});

it('does not refetch the JWKS when the cached keys know the token', function () {
    retryPrimeDiscovery();
    [$private, $jwk] = rotationKeyPair('current-key');
    Cache::put(criiptoCacheKey('jwks'), ['keys' => [$jwk]], 3600);

    $token = \Firebase\JWT\JWT::encode(['sub' => 'user-2', 'exp' => time() + 60], $private, 'RS256', 'current-key');
    $mock = new MockHandler([new Response(200, [], json_encode(['keys' => []]))]);
    $provider = retryProvider($mock);
    $method = (new ReflectionClass($provider))->getMethod('getUserByToken');

    expect($method->invoke($provider, $token)['sub'])->toBe('user-2');
    expect($mock->count())->toBe(1); // nothing fetched
});

it('still refuses a token whose key Criipto does not publish', function () {
    retryPrimeDiscovery();
    [, $jwk] = rotationKeyPair('current-key');
    [$foreignPrivate] = rotationKeyPair('foreign-key');
    Cache::put(criiptoCacheKey('jwks'), ['keys' => [$jwk]], 3600);

    $token = \Firebase\JWT\JWT::encode(['sub' => 'attacker', 'exp' => time() + 60], $foreignPrivate, 'RS256', 'foreign-key');
    $mock = new MockHandler([new Response(200, [], json_encode(['keys' => [$jwk]]))]);
    $provider = retryProvider($mock);
    $method = (new ReflectionClass($provider))->getMethod('getUserByToken');

    expect(fn () => $method->invoke($provider, $token))->toThrow(Exception::class);
    expect($mock->count())->toBe(0); // refetched once, still unknown
});
