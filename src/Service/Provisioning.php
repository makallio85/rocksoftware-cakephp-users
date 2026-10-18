<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use RockSoftware\Users\Model\Entity\Setting;
use RockSoftware\Users\UsersPlugin;
use Throwable;

/**
 * `POST /auth/provision`: the identity provider hands the application its
 * connection (issuer, client ID, client secret, required group, and the SCIM
 * token it will push people with, `scim_token`), so connecting an application
 * needs no shell. rocksoftware-identity#5, *Connecting
 * applications automatically*, holds the design.
 *
 * The request carries a bearer token the provider issued through token
 * exchange for the very client it delivers. It is accepted only when:
 *
 * - the delivered issuer is under the trusted prefix, checked before anything
 *   is fetched, so discovery and keys only ever come from the trusted
 *   provider and an attacker cannot point the application elsewhere;
 * - the token is signed with that issuer's key, and its `iss` and `aud` equal
 *   the delivered issuer and client ID, so a token made for one application
 *   cannot configure another;
 * - it was issued to the onboarding account, at most `maxAge` seconds ago, and
 *   its `jti` has not been used before;
 * - the application accepts provisioning at all: before its first connection,
 *   or inside a window an administrator opened on the settings page. Each
 *   provisioning closes it again, so a connected application cannot be
 *   rewired from outside.
 *
 * Every refusal is a bare 401 (a malformed request 400, too many 429), and the
 * reason goes to the log without the request body, which holds the secret.
 */
class Provisioning
{
    use LocatorAwareTrait;

    public const EVENT = 'RockSoftwareUsers.provisioned';

    private const RATE_KEY = 'rock_software_users_provision_rate';

    /**
     * @param \Cake\Http\Client|null $http Injected by tests.
     */
    public function __construct(private ?Client $http = null)
    {
    }

    /**
     * The provisioning settings, or null when the endpoint is switched off.
     *
     * @return array{issuerPrefix: string, subject: string, maxAge: int, rateLimit: int, rateWindow: int}|null
     */
    public static function config(): ?array
    {
        $config = Configure::read('RockSoftwareUsers.provisioning');
        if ($config === false) {
            return null;
        }
        /** @var array{issuerPrefix: string, subject: string, maxAge: int, rateLimit: int, rateWindow: int} */
        return array_replace(UsersPlugin::DEFAULTS['provisioning'], is_array($config) ? $config : []);
    }

    /**
     * @param string $authorization The Authorization header.
     * @param string $body The raw request body (JSON).
     * @param string|null $remoteIp The client address, for the record.
     * @return int The HTTP status to answer with.
     */
    public function handle(string $authorization, string $body, ?string $remoteIp): int
    {
        $config = self::config();
        if ($config === null) {
            return 404;
        }
        if (!$this->withinRateLimit($config)) {
            Log::warning('Provisioning refused: too many requests.');

            return 429;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return 400;
        }
        $issuer = $data['issuer'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $secret = $data['client_secret'] ?? null;
        $group = $data['required_group'] ?? null;
        $scimToken = $data['scim_token'] ?? null;
        if (
            $scimToken !== null
            && (!is_string($scimToken) || !preg_match('/^[A-Za-z0-9_\-]{40,255}$/', $scimToken))
        ) {
            return 400;
        }
        if (
            !is_string($issuer) || !is_string($clientId) || !is_string($secret)
            || ($group !== null && !is_string($group))
            || $clientId === '' || $secret === '' || strlen($clientId) > 255 || strlen($secret) > 255
            || ($group !== null && strlen($group) > 255)
        ) {
            return 400;
        }
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        if (!$settings->provisioningOpen()) {
            Log::warning('Provisioning refused: closed; an administrator can allow it on the settings page.');

            return 401;
        }
        $issuer = rtrim($issuer, '/') . '/';
        if (!self::trustedIssuer($issuer, $config['issuerPrefix'])) {
            Log::warning('Provisioning refused: untrusted issuer.');

            return 401;
        }
        if (!preg_match('/^Bearer ([A-Za-z0-9_\-.]+)$/', $authorization, $match)) {
            return 401;
        }

        $setting = new Setting();
        $setting->set('issuer_url', $issuer, ['guard' => false]);
        $setting->set('client_id', $clientId, ['guard' => false]);
        try {
            $client = new OidcClient($setting, $secret, $this->http);
            // The keys must come from the trusted provider as well.
            $jwksUri = (string)$client->discovery()['jwks_uri'];
            if (!str_starts_with($jwksUri, self::origin($config['issuerPrefix']) . '/')) {
                throw new SignInException('The discovery document names keys on another host.');
            }
            $claims = $client->validateProvisioningToken($match[1], $config['subject'], $config['maxAge']);
        } catch (SignInException $e) {
            Log::warning('Provisioning refused: ' . $e->getMessage());

            return 401;
        } catch (Throwable $e) {
            Log::warning('Provisioning refused: ' . get_class($e));

            return 401;
        }

        /** @var \RockSoftware\Users\Model\Table\ProvisioningsTable $provisionings */
        $provisionings = $this->fetchTable('RockSoftware/Users.Provisionings');
        $jti = (string)$claims['jti'];
        try {
            $provisionings->getConnection()->transactional(
                function () use (
                    $provisionings,
                    $settings,
                    $jti,
                    $issuer,
                    $clientId,
                    $secret,
                    $group,
                    $scimToken,
                    $remoteIp,
                ): void {
                    if ($provisionings->used($jti)) {
                        throw new SignInException('The provisioning token was used before.');
                    }
                    // Checked again inside the transaction: two requests in the
                    // same window must not both get through.
                    if (!$settings->provisioningOpen()) {
                        throw new SignInException('Provisioning closed meanwhile.');
                    }
                    // The unique index on jti refuses a concurrent replay too.
                    $provisionings->saveOrFail($provisionings->newEntity([
                        'jti' => $jti,
                        'issuer_url' => $issuer,
                        'client_id' => $clientId,
                        'remote_ip' => $remoteIp,
                    ]));
                    $values = [
                        'issuer_url' => $issuer,
                        'client_id' => $clientId,
                        'client_secret' => $secret,
                        'required_group' => $group === null || trim($group) === '' ? null : trim($group),
                        'is_enabled' => true,
                        'provisioning_open_until' => null,
                    ];
                    if (is_string($scimToken)) {
                        // Only its hash is kept; Rock Software ID holds the token.
                        $values['scim_token_hash'] = hash('sha256', $scimToken);
                    }
                    $settings->store($values);
                },
            );
        } catch (Throwable $e) {
            $reason = $e instanceof SignInException ? $e->getMessage() : get_class($e);
            Log::warning('Provisioning refused: ' . $reason);

            return 401;
        }

        Log::notice(sprintf('Connection provisioned by %s for client %s.', $issuer, $clientId));
        EventManager::instance()->dispatch(new Event(self::EVENT, $this, [
            'issuer' => $issuer,
            'clientId' => $clientId,
            'requiredGroup' => $group,
            'scim' => is_string($scimToken),
        ]));

        return 204;
    }

    /**
     * Whether an issuer is one application path directly under the trusted prefix.
     *
     * @param string $issuer The issuer, with one trailing slash.
     * @param string $prefix The trusted prefix, e.g. `https://id.rocksoftware.fi/application/o/`.
     * @return bool
     */
    public static function trustedIssuer(string $issuer, string $prefix): bool
    {
        if ($prefix === '' || !str_starts_with($prefix, 'https://') || !str_starts_with($issuer, $prefix)) {
            return false;
        }

        return (bool)preg_match('#^[a-z0-9][a-z0-9_-]*/$#', substr($issuer, strlen($prefix)));
    }

    /**
     * @param string $url A URL.
     * @return string Its scheme and host (and port).
     */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . $port;
    }

    /**
     * One counter for the endpoint: provisioning is rare, and behind a proxy
     * every caller shares an address anyway.
     *
     * @param array{rateLimit: int, rateWindow: int} $config The settings.
     * @return bool
     */
    private function withinRateLimit(array $config): bool
    {
        $cache = (string)Configure::read('RockSoftwareUsers.cacheConfig', 'default');
        $now = time();
        $window = Cache::read(self::RATE_KEY, $cache);
        if (!is_array($window) || ($window['start'] ?? 0) <= $now - $config['rateWindow']) {
            $window = ['start' => $now, 'count' => 0];
        }
        $window['count']++;
        Cache::write(self::RATE_KEY, $window, $cache);

        return $window['count'] <= $config['rateLimit'];
    }
}
