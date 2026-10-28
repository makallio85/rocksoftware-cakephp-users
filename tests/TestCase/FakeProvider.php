<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase;

use Cake\Http\Client;
use Cake\Http\Client\Response;
use Firebase\JWT\JWT;
use stdClass;

/**
 * An identity provider that exists only as mocked HTTP responses: a discovery
 * document, a JWKS with one RSA key, and a token endpoint that returns
 * whatever ID token the test signed.
 */
final class FakeProvider
{
    public const ISSUER = 'https://id.example.test/application/o/app/';
    public const CLIENT_ID = 'app-client';
    public const CLIENT_SECRET = 'app-secret';
    public const KID = 'test-key';
    /** The audience of access tokens: the client of the application's MCP endpoint. */
    public const MCP_AUDIENCE = 'app-mcp-client';

    private string $privateKey;

    /**
     * @var array<string, mixed>
     */
    private array $jwk;

    /**
     * Keys published besides the first, by key id.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $extraJwks = [];

    public function __construct()
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);
        $this->privateKey = $privateKey;
        $details = openssl_pkey_get_details($resource);
        $this->jwk = [
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ];
    }

    /**
     * Registers the discovery document and the JWKS.
     *
     * @return void
     */
    public function serveMetadata(string $issuer = self::ISSUER, ?string $jwksUri = null, ?int &$jwksFetches = null): void
    {
        $jwksUri ??= $issuer . 'jwks/';
        Client::addMockResponse('GET', $issuer . '.well-known/openid-configuration', self::json([
            'issuer' => $issuer,
            'authorization_endpoint' => 'https://id.example.test/application/o/authorize/',
            'token_endpoint' => 'https://id.example.test/application/o/token/',
            'jwks_uri' => $jwksUri,
            'end_session_endpoint' => $issuer . 'end-session/',
        ]));
        $jwksFetches = 0;
        Client::addMockResponse(
            'GET',
            $jwksUri,
            self::json(['keys' => array_values([$this->jwk] + $this->extraJwks)]),
            ['match' => function () use (&$jwksFetches): bool {
                $jwksFetches++;

                return true;
            }],
        );
    }

    /**
     * Publishes one more key (on the next `serveMetadata()`), and returns its
     * private key.
     *
     * @param string $kid Its key id.
     * @param string $type `RSA` (RS256) or `EC` (P-256, ES256).
     * @param string|null $alg The `alg` the JWKS declares for it; none when null.
     * @return string
     */
    public function addKey(string $kid, string $type = 'RSA', ?string $alg = 'RS256'): string
    {
        if ($type === 'EC') {
            $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            openssl_pkey_export($resource, $privateKey);
            $details = openssl_pkey_get_details($resource);
            $jwk = [
                'kty' => 'EC',
                'kid' => $kid,
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => self::b64(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
                'y' => self::b64(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            ];
        } else {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $privateKey);
            $details = openssl_pkey_get_details($resource);
            $jwk = [
                'kty' => 'RSA',
                'kid' => $kid,
                'use' => 'sig',
                'n' => self::b64($details['rsa']['n']),
                'e' => self::b64($details['rsa']['e']),
            ];
        }
        if ($alg !== null) {
            $jwk['alg'] = $alg;
        }
        $this->extraJwks[$kid] = $jwk;

        return $privateKey;
    }

    /**
     * An access token for the application's MCP client, as Rock Software ID
     * issues it, with defaults overridable per claim.
     *
     * @param array<string, mixed> $claims Claims to set or override.
     * @param string|null $privateKey Signs with another key when given.
     * @param string $alg The signature algorithm.
     * @param string|null $kid The key id in the header; none when null.
     * @return string
     */
    public function accessToken(
        array $claims = [],
        ?string $privateKey = null,
        string $alg = 'RS256',
        ?string $kid = self::KID,
    ): string {
        $now = time();

        return JWT::encode($claims + [
            'iss' => self::ISSUER,
            'aud' => self::MCP_AUDIENCE,
            'azp' => self::MCP_AUDIENCE,
            'sub' => 'subject-1',
            'scope' => 'openid email profile',
            'groups' => ['app-staff'],
            'jti' => bin2hex(random_bytes(8)),
            'iat' => $now,
            'exp' => $now + 300,
        ], $privateKey ?? $this->privateKey, $alg, $kid);
    }

    /**
     * Makes the token endpoint return this ID token.
     *
     * @param string $idToken The signed token.
     * @return void
     */
    public function serveIdToken(string $idToken): void
    {
        Client::addMockResponse(
            'POST',
            'https://id.example.test/application/o/token/',
            self::json(['access_token' => 'access', 'token_type' => 'Bearer', 'id_token' => $idToken]),
        );
    }

    /**
     * An ID token with sensible defaults, overridable per claim.
     *
     * @param array<string, mixed> $claims Claims to set or override.
     * @param string|null $privateKey Signs with another key when given.
     * @return string
     */
    public function idToken(array $claims, ?string $privateKey = null): string
    {
        $now = time();

        return JWT::encode($claims + [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-1',
            'email' => 'person@example.test',
            'email_verified' => true,
            'name' => 'Person One',
            'groups' => ['app-staff'],
            'iat' => $now,
            'exp' => $now + 300,
        ], $privateKey ?? $this->privateKey, 'RS256', self::KID);
    }

    /**
     * A back-channel logout token.
     *
     * @param array<string, mixed> $claims Claims to set or override.
     * @return string
     */
    public function logoutToken(array $claims = []): string
    {
        $now = time();

        return JWT::encode($claims + [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-1',
            'iat' => $now,
            'exp' => $now + 120,
            'jti' => bin2hex(random_bytes(8)),
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new stdClass()],
        ], $this->privateKey, 'RS256', self::KID);
    }

    /**
     * A provisioning token, as token exchange issues it for this client to
     * the onboarding account.
     *
     * @param array<string, mixed> $claims Claims to set or override.
     * @param string|null $privateKey Signs with another key when given.
     * @return string
     */
    public function provisioningToken(array $claims = [], ?string $privateKey = null): string
    {
        $now = time();

        return JWT::encode($claims + [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'azp' => self::CLIENT_ID,
            'sub' => 'onboarding-subject',
            'preferred_username' => 'onboarding',
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + 3600,
        ], $privateKey ?? $this->privateKey, 'RS256', self::KID);
    }

    /**
     * A private key the provider does not publish.
     *
     * @return string
     */
    public static function foreignKey(): string
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);

        return $privateKey;
    }

    /**
     * @param array<string, mixed> $body JSON body.
     * @return \Cake\Http\Client\Response
     */
    private static function json(array $body): Response
    {
        return new Response(['HTTP/1.1 200 OK', 'Content-Type: application/json'], (string)json_encode($body));
    }

    /**
     * @param string $bytes Raw bytes.
     * @return string
     */
    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
