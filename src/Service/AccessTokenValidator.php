<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Http\Client;
use RockSoftware\Users\Model\Entity\Setting;

/**
 * Validates an access token (a bearer token) that Rock Software ID issued for
 * one of the application's API or MCP paths. Nothing is sent anywhere: the
 * token is checked locally against the issuer's published keys
 * (`OidcClient::validateAccessToken()`), which are cached and fetched again
 * once for an unknown key id.
 *
 * The issuer is pinned by the stored connection: its own issuer, or the
 * access-token issuer set on the settings page (`bearer_issuer_url`), which
 * must be on the same host, as must the keys the issuer names. A token from
 * anywhere else is refused before anything is fetched.
 */
class AccessTokenValidator
{
    /**
     * @param \RockSoftware\Users\Model\Entity\Setting $connection The stored connection.
     * @param \Cake\Http\Client|null $http Injected by tests.
     */
    public function __construct(private Setting $connection, private ?Client $http = null)
    {
    }

    /**
     * The issuer access tokens must come from.
     *
     * @param \RockSoftware\Users\Model\Entity\Setting $connection The stored connection.
     * @return string
     */
    public static function issuer(Setting $connection): string
    {
        $issuer = $connection->bearer_issuer_url;

        return is_string($issuer) && $issuer !== '' ? $issuer : $connection->issuer_url;
    }

    /**
     * Whether an issuer may issue access tokens here: an https address on the
     * host of the sign-in connection's issuer.
     *
     * @param string $issuer The issuer.
     * @param string $connectionIssuer The sign-in connection's issuer.
     * @return bool
     */
    public static function trustedIssuer(string $issuer, string $connectionIssuer): bool
    {
        $origin = self::origin($issuer);

        return str_starts_with($issuer, 'https://') && $origin !== '' && $origin === self::origin($connectionIssuer);
    }

    /**
     * @param string $jwt The bearer token.
     * @param string $audience The audience it must name.
     * @return \RockSoftware\Users\Service\AccessTokenClaims
     * @throws \RockSoftware\Users\Service\SignInException When it is not valid here.
     */
    public function validate(string $jwt, string $audience): AccessTokenClaims
    {
        $issuer = self::issuer($this->connection);
        if (!self::trustedIssuer($issuer, $this->connection->issuer_url)) {
            throw new SignInException('The access-token issuer is not on the identity server\'s host.');
        }
        $setting = new Setting();
        $setting->set('issuer_url', $issuer, ['guard' => false]);
        $setting->set('client_id', $audience, ['guard' => false]);
        $client = new OidcClient($setting, '', $this->http);

        $jwksUri = (string)$client->discovery()['jwks_uri'];
        if (self::origin($jwksUri) !== self::origin($issuer)) {
            throw new SignInException('The discovery document names keys on another host.');
        }

        return AccessTokenClaims::fromClaims($client->validateAccessToken($jwt, $audience));
    }

    /**
     * @param string $url A URL.
     * @return string Its scheme and host (and port), lower case.
     */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme'] . '://' . $parts['host']) . $port;
    }
}
