<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Client;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RockSoftware\Users\Model\Entity\Setting;
use Throwable;

/**
 * The OpenID Connect relying party: the authorization code flow with PKCE,
 * ID token and logout token validation, and the end-session URL.
 *
 * Tokens are verified against the provider's published keys (JWKS) and only
 * asymmetric signatures are accepted, so a token cannot be forged with the
 * client secret.
 */
class OidcClient
{
    public const SCOPES = 'openid email profile';

    private const BACKCHANNEL_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    /**
     * Accepted signature algorithms. Symmetric algorithms are deliberately
     * absent.
     *
     * @var array<string>
     */
    private const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'];

    private const LEEWAY_SECONDS = 60;

    private Client $http;

    /**
     * @param \RockSoftware\Users\Model\Entity\Setting $setting The provider connection.
     * @param string $clientSecret The decrypted client secret.
     * @param \Cake\Http\Client|null $http Injected by tests.
     */
    public function __construct(
        private Setting $setting,
        private string $clientSecret,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 10]);
    }

    /**
     * The provider's discovery document, cached.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $key = 'rock_software_users_discovery_' . md5($this->setting->issuer_url);
        $cached = Cache::read($key, self::cacheConfig());
        if (is_array($cached)) {
            return $cached;
        }

        $document = $this->getJson($this->setting->issuer_url . '.well-known/openid-configuration');
        $issuer = $document['issuer'] ?? null;
        if (!is_string($issuer) || rtrim($issuer, '/') !== rtrim($this->setting->issuer_url, '/')) {
            throw new SignInException('The discovery document names a different issuer.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (!is_string($document[$required] ?? null)) {
                throw new SignInException(sprintf('The discovery document has no %s.', $required));
            }
        }

        Cache::write($key, $document, self::cacheConfig());

        return $document;
    }

    /**
     * Where to send the browser to sign in.
     *
     * @param string $redirectUri This application's callback URL.
     * @param string $state Binds the response to this browser session.
     * @param string $nonce Binds the ID token to this request.
     * @param string $codeVerifier The PKCE verifier; only its challenge is sent.
     * @return string
     */
    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeVerifier): string
    {
        $endpoint = (string)$this->discovery()['authorization_endpoint'];
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->setting->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query;
    }

    /**
     * Exchanges an authorization code for tokens.
     *
     * @param string $code The code from the callback.
     * @param string $redirectUri The callback URL used in the request.
     * @param string $codeVerifier The PKCE verifier.
     * @return array<string, mixed> The token response.
     */
    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): array
    {
        $response = $this->http->post(
            (string)$this->discovery()['token_endpoint'],
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ],
            ['auth' => ['username' => $this->setting->client_id, 'password' => $this->clientSecret]],
        );
        if (!$response->isOk()) {
            throw new SignInException(sprintf('The token endpoint answered %d.', $response->getStatusCode()));
        }
        $body = $response->getJson();
        if (!is_array($body) || !is_string($body['id_token'] ?? null)) {
            throw new SignInException('The token response has no ID token.');
        }

        return $body;
    }

    /**
     * Verifies an ID token and returns its claims.
     *
     * @param string $idToken The token.
     * @param string $nonce The nonce sent with the authorization request.
     * @return array<string, mixed>
     */
    public function validateIdToken(string $idToken, string $nonce): array
    {
        $claims = $this->decode($idToken);

        $tokenNonce = $claims['nonce'] ?? null;
        if (!is_string($tokenNonce) || !hash_equals($nonce, $tokenNonce)) {
            throw new SignInException('The ID token nonce does not match.');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new SignInException('The ID token has no subject.');
        }

        return $claims;
    }

    /**
     * Verifies a back-channel logout token and returns its claims.
     *
     * @param string $logoutToken The token.
     * @return array<string, mixed>
     */
    public function validateLogoutToken(string $logoutToken): array
    {
        $claims = $this->decode($logoutToken);

        $events = $claims['events'] ?? null;
        if (!is_array($events) || !array_key_exists(self::BACKCHANNEL_LOGOUT_EVENT, $events)) {
            throw new SignInException('The logout token carries no back-channel logout event.');
        }
        if (array_key_exists('nonce', $claims)) {
            throw new SignInException('A logout token must not carry a nonce.');
        }
        if (!is_string($claims['sub'] ?? null) && !is_string($claims['sid'] ?? null)) {
            throw new SignInException('The logout token names neither a subject nor a session.');
        }

        return $claims;
    }

    /**
     * Verifies a provisioning token and returns its claims: one the identity
     * provider issued through token exchange for this client (issuer and
     * audience checked by `decode()`), to the given subject, recently, with an
     * id the caller records so it is accepted once.
     *
     * @param string $token The bearer token.
     * @param string $subject The `preferred_username` it must be issued to.
     * @param int $maxAge Seconds since `iat` it is accepted for.
     * @return array<string, mixed>
     */
    public function validateProvisioningToken(string $token, string $subject, int $maxAge): array
    {
        $claims = $this->decode($token);

        if (($claims['preferred_username'] ?? null) !== $subject) {
            throw new SignInException('The provisioning token was issued to a different account.');
        }
        $issuedAt = $claims['iat'] ?? null;
        if (!is_int($issuedAt) || $issuedAt < time() - $maxAge - self::LEEWAY_SECONDS) {
            throw new SignInException('The provisioning token is too old.');
        }
        if (!is_string($claims['jti'] ?? null) || $claims['jti'] === '' || strlen($claims['jti']) > 190) {
            throw new SignInException('The provisioning token has no usable id.');
        }

        return $claims;
    }

    /**
     * Where to send the browser to end the provider session, or null when the
     * provider publishes no end-session endpoint.
     *
     * @param string|null $idTokenHint The ID token from sign-in.
     * @param string $postLogoutRedirectUri Where the provider sends the browser afterwards.
     * @return string|null
     */
    public function endSessionUrl(?string $idTokenHint, string $postLogoutRedirectUri): ?string
    {
        $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        if (!is_string($endpoint)) {
            return null;
        }
        $query = array_filter([
            'id_token_hint' => $idTokenHint,
            'client_id' => $this->setting->client_id,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
        ]);

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?')
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * A new random value for state, nonce or PKCE verifier.
     *
     * @return string
     */
    public static function randomToken(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * @param string $codeVerifier The PKCE verifier.
     * @return string
     */
    public static function codeChallenge(string $codeVerifier): string
    {
        return self::base64Url(hash('sha256', $codeVerifier, true));
    }

    /**
     * Verifies signature, issuer, audience and lifetime.
     *
     * @param string $jwt The token.
     * @return array<string, mixed>
     */
    private function decode(string $jwt): array
    {
        $header = $this->header($jwt);
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || !in_array($alg, self::ALGORITHMS, true)) {
            throw new SignInException('The token is not signed with an accepted algorithm.');
        }

        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $payload = JWT::decode($jwt, $this->keys($alg, false));
        } catch (Throwable $first) {
            // An unknown key id means the provider has rotated its keys since
            // they were cached. Fetch them once more before giving up.
            try {
                $payload = JWT::decode($jwt, $this->keys($alg, true));
            } catch (Throwable $e) {
                throw new SignInException('The token signature or lifetime is invalid: ' . $e->getMessage(), 0, $e);
            }
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string)json_encode($payload), true);

        $issuer = $claims['iss'] ?? null;
        if (!is_string($issuer) || rtrim($issuer, '/') !== rtrim($this->setting->issuer_url, '/')) {
            throw new SignInException('The token was issued by a different issuer.');
        }
        $audience = (array)($claims['aud'] ?? []);
        if (!in_array($this->setting->client_id, $audience, true)) {
            throw new SignInException('The token was issued to a different client.');
        }
        if (count($audience) > 1 && ($claims['azp'] ?? null) !== $this->setting->client_id) {
            throw new SignInException('The token names several audiences but not this client as the authorized party.');
        }
        if (!isset($claims['exp'])) {
            throw new SignInException('The token has no expiry.');
        }

        return $claims;
    }

    /**
     * @param string $alg The token's algorithm, used for keys that do not state one.
     * @param bool $refresh Bypass the cache.
     * @return array<string, \Firebase\JWT\Key>
     */
    private function keys(string $alg, bool $refresh): array
    {
        $uri = (string)$this->discovery()['jwks_uri'];
        $key = 'rock_software_users_jwks_' . md5($uri);
        $jwks = $refresh ? null : Cache::read($key, self::cacheConfig());
        if (!is_array($jwks)) {
            $jwks = $this->getJson($uri);
            Cache::write($key, $jwks, self::cacheConfig());
        }

        return JWK::parseKeySet($jwks, $alg);
    }

    /**
     * @param string $jwt The token.
     * @return array<string, mixed>
     */
    private function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SignInException('The token is malformed.');
        }
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
        if (!is_array($header)) {
            throw new SignInException('The token header is malformed.');
        }

        return $header;
    }

    /**
     * @param string $url The URL.
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        try {
            $response = $this->http->get($url);
        } catch (Throwable $e) {
            throw new SignInException(sprintf('The identity provider is unreachable (%s).', $e->getMessage()), 0, $e);
        }
        if (!$response->isOk()) {
            throw new SignInException(sprintf('%s answered %d.', $url, $response->getStatusCode()));
        }
        $body = $response->getJson();
        if (!is_array($body)) {
            throw new SignInException(sprintf('%s did not return JSON.', $url));
        }

        return $body;
    }

    /**
     * @return string
     */
    private static function cacheConfig(): string
    {
        return (string)Configure::read('RockSoftwareUsers.cacheConfig', 'default');
    }

    /**
     * @param string $bytes Raw bytes.
     * @return string
     */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
