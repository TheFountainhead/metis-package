<?php

namespace TheFountainhead\Metis\Auth;

use CoderCat\JWKToPEM\JWKConverter;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User;
use TheFountainhead\Metis\Exceptions\CriiptoUnreachableException;

class SocialiteCriiptoProvider extends AbstractProvider
{
    /**
     * Cached OpenID configuration to avoid repeated HTTP calls within a single request.
     */
    private ?object $openIdConfiguration = null;

    /**
     * Unique Provider Identifier.
     */
    const IDENTIFIER = 'criipto';

    private const CIRCUIT_OPEN_KEY = 'criipto:circuit-open';

    private const FAILURES_KEY = 'criipto:connection-failures';

    /**
     * @var string[]
     */
    protected $scopes = [
        'openid',
    ];

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(
            $this->getOpenIdConfiguration()->authorization_endpoint,
            $state
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return $this->getOpenIdConfiguration()->token_endpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function user()
    {
        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $response = $this->getAccessTokenResponse($this->getCode());
        $this->credentialsResponseBody = $response;

        $user = $this->mapUserToObject($this->getUserByToken(
            $token = $this->parseIdToken($response)
        ));

        session(['socialite_'.self::IDENTIFIER.'_idtoken' => $token]);

        return $user->setToken($token);
    }

    /**
     * Get the id token from the token response body.
     *
     * @param  string  $body
     * @return string
     */
    protected function parseIdToken($body)
    {
        return Arr::get($body, 'id_token');
    }

    /**
     * Get the access token response for the given code.
     *
     * @param  string  $code
     * @return array
     */
    public function getAccessTokenResponse($code)
    {
        $response = $this->retryBeforeSend(fn () => $this->getHttpClient()->post($this->getTokenUrl(), [
            'headers' => [
                'Accept' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
            ],
            'form_params' => $this->getTokenFields($code),
            'timeout' => 15,
            'connect_timeout' => 5,
        ]));

        return json_decode($response->getBody(), true);
    }

    /**
     * Get the raw user for the given id token.
     *
     * @param  string  $token
     * @return array
     */
    protected function getUserByToken($token)
    {
        // Reading public keys from criipto for validating the JWT token
        $keys = $this->getJWTKeys();

        // Get the algorithm from the token header
        $tokenParts = explode('.', $token);
        $header = json_decode(base64_decode(strtr($tokenParts[0], '-_', '+/')), true); // base64url
        $kid = $header['kid'] ?? null;

        if (! isset($keys[$kid])) {
            // Criipto rotated its signing keys after the JWKS was cached: fetch
            // it once more. Only the JWKS GET repeats, never the code exchange.
            Cache::forget($this->cacheKey('jwks'));
            $keys = $this->getJWTKeys();
        }

        if (! isset($keys[$kid])) {
            throw new Exception('Unable to find a valid key for token verification');
        }

        return (array) JWT::decode($token, $keys[$kid]);
    }

    /**
     * Get the current JWT signing keys in an openssl supported format
     *
     * @return array
     */
    private function getJWTKeys()
    {
        $jwks = Cache::remember($this->cacheKey('jwks'), 3600, function () {
            $response = $this->retryBeforeSend(fn () => $this->getHttpClient()->get($this->getOpenIdConfiguration()->jwks_uri, [
                'timeout' => 10,
                'connect_timeout' => 5,
            ]));

            return json_decode($response->getBody(), true);
        });
        $public_keys = [];

        // Get the algorithm - typically RS256 for JWT
        $algorithm = $this->getOpenIdConfiguration()->id_token_signing_alg_values_supported[0] ?? 'RS256';

        foreach ($jwks['keys'] as $jwk) {
            $jwkConverter = new JWKConverter;
            $pem = $jwkConverter->toPEM($jwk);
            $public_keys[$jwk['kid']] = new Key($pem, $algorithm);
        }

        return $public_keys;
    }

    /**
     * Get the OpenID configuration from criipto
     */
    private function getOpenIdConfiguration()
    {
        if ($this->openIdConfiguration) {
            return $this->openIdConfiguration;
        }

        $url = config('services.criipto.base_uri').'/.well-known/openid-configuration';

        $this->openIdConfiguration = Cache::remember($this->cacheKey('openid-config'), 3600, function () use ($url) {
            try {
                $response = $this->retryBeforeSend(fn () => $this->getHttpClient()->get($url, [
                    'http_errors' => true,
                    'timeout' => 10,
                    'connect_timeout' => 5,
                ]));
            } catch (ConnectException $e) {
                throw new CriiptoUnreachableException("Unable to connect to the Criipto identity provider ({$url}). DNS or network error: ".$e->getMessage(), previous: $e);
            } catch (ClientException $e) {
                throw new Exception('Unable to read the OpenID configuration. Make sure the base_uri is set correctly', previous: $e);
            }

            return json_decode($response->getBody());
        });

        return $this->openIdConfiguration;
    }

    /**
     * Cache key per Criipto base URI, so a changed CRIIPTO_URI never reads
     * another tenant's endpoints or keys from the cache.
     */
    private function cacheKey(string $name): string
    {
        return "criipto:{$name}:".md5((string) config('services.criipto.base_uri'));
    }

    /**
     * Send a request to Criipto, retrying when it never left this server.
     *
     * Hetzner's resolvers dropped ~27% of uncached lookups on 8 Oct 2026, and
     * one lost DNS answer was enough to fail a MitID login (Frankston-master
     * #2176). A failed name lookup or TCP connect is retried, up to three
     * attempts. A timeout after the request may have been sent is never
     * retried: the token exchange spends a single-use OAuth code.
     */
    private function retryBeforeSend(callable $send)
    {
        if (Cache::has(self::CIRCUIT_OPEN_KEY)) {
            throw new CriiptoUnreachableException('Criipto circuit breaker is open after repeated connection failures; not calling the identity provider.');
        }

        try {
            $response = retry(3, $send, 500, fn ($e) => $e instanceof ConnectException && $this->failedBeforeSend($e));
        } catch (ConnectException $e) {
            $this->recordConnectionFailure();

            throw $e;
        }

        Cache::forget(self::FAILURES_KEY);

        return $response;
    }

    /**
     * Whether curl failed while resolving or connecting, i.e. before any
     * request bytes reached Criipto.
     */
    private function failedBeforeSend(ConnectException $e): bool
    {
        $errno = $e->getHandlerContext()['errno'] ?? null;

        // CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT
        if (in_array($errno, [6, 7], true)) {
            return true;
        }

        // CURLE_OPERATION_TIMEDOUT covers both phases; only the resolve/connect
        // wording proves nothing was sent (libcurl 8.5 on the servers).
        return $errno === 28
            && preg_match('/^cURL error 28: (Resolving timed out|Failed to connect to)/', $e->getMessage()) === 1;
    }

    /**
     * Circuit breaker. A sustained outage makes every login hold a PHP-FPM
     * worker ~16s before failing. After three calls in a row failed to reach
     * Criipto, less than 60s apart, Criipto is not called for 30s: logins fail
     * at once with CriiptoUnreachableException, which the callbacks render as
     * a MitID "try again" message. A failed call is a pre-send failure that
     * outlasted the retries, or a timeout after sending (never retried, but it
     * holds a worker just as long). A call that succeeds, also after a retry,
     * resets the count.
     */
    private function recordConnectionFailure(): void
    {
        // get + put, not add + increment: an increment on a key that expired in
        // between is stored without a TTL. A lost count under concurrent
        // failures only opens the breaker a little later.
        $failures = Cache::get(self::FAILURES_KEY, 0) + 1;

        if ($failures < 3) {
            Cache::put(self::FAILURES_KEY, $failures, 60);

            return;
        }

        Cache::forget(self::FAILURES_KEY);
        Cache::put(self::CIRCUIT_OPEN_KEY, true, 30);

        Log::error('Criipto circuit breaker opened: three calls in a row could not reach the identity provider. MitID logins fail fast for 30s.');
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'],
        ]);
    }

    /**
     * Get the POST fields for the token request.
     *
     * @param  string  $code
     * @return array
     */
    protected function getTokenFields($code)
    {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
        ];
    }

    /**
     * Add additional required config items
     *
     * @return array
     */
    public static function additionalConfigKeys()
    {
        return ['base_uri'];
    }

    /**
     * Tell Criipto the user has signed out
     */
    public function logOut($guard, $user)
    {
        $idToken = session('socialite_'.self::IDENTIFIER.'_idtoken');
        if (! empty($idToken)) {
            abort(redirect($this->getOpenIdConfiguration()->end_session_endpoint.'?id_token_hint='.$idToken.'&post_logout_redirect_uri='.urlencode($this->config['redirect_logout'] ?? request()->fullUrl())));
        }
    }
}
