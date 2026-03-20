<?php

namespace TheFountainhead\Metis\Auth;

use CoderCat\JWKToPEM\JWKConverter;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User;

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
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            'headers' => [
                'Accept' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
            ],
            'form_params' => $this->getTokenFields($code),
        ]);

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
        $header = json_decode(base64_decode($tokenParts[0]), true);
        $kid = $header['kid'] ?? null;

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
        $response = $this->getHttpClient()->get($this->getOpenIdConfiguration()->jwks_uri);
        $jwks = json_decode($response->getBody(), true);
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
        $lastException = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = $this->getHttpClient()->get($url, ['http_errors' => true]);
                $this->openIdConfiguration = json_decode($response->getBody());

                return $this->openIdConfiguration;
            } catch (ConnectException $e) {
                $lastException = $e;
                usleep(500_000); // 500ms before retry
            } catch (ClientException $e) {
                throw new Exception('Unable to read the OpenID configuration. Make sure the base_uri is set correctly');
            }
        }

        throw new Exception("Unable to connect to the Criipto identity provider ({$url}). DNS or network error: ".$lastException->getMessage());
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
