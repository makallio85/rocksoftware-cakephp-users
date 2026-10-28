<?php
declare(strict_types=1);

namespace RockSoftware\Users\Middleware;

use Authentication\AuthenticationService;
use Authentication\Authenticator\Result;
use Authentication\Identity;
use Authentication\IdentityInterface;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Response;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use DateTimeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RockSoftware\Settings\Settings;
use RockSoftware\Settings\SettingsPlugin;
use RockSoftware\Users\Service\AccessTokenClaims;
use RockSoftware\Users\Service\AccessTokenValidator;
use RockSoftware\Users\Service\IdentityProviderUnavailableException;
use RockSoftware\Users\Service\SignInException;
use RockSoftware\Users\Service\SignInHistory;
use RockSoftware\Users\Service\UserAccess;
use RockSoftware\Users\Service\UserProvisioner;
use RockSoftware\Users\UsersPlugin;
use Throwable;

/**
 * Signs a request in with an access token (`Authorization: Bearer …`) on the
 * paths the application lists in `RockSoftwareUsers.bearer.paths`: its API or
 * MCP endpoint. Every other request passes through untouched.
 *
 * Those paths are stateless. A request on them without a session cookie is a
 * bearer request (`isBearerRequest()`): it needs a valid token, no session is
 * started for it, and the application leaves it out of its CSRF check. A
 * request that carries a session cookie is a browser's and goes the usual
 * way (session and CSRF), so a token never mixes with a session.
 *
 * A bearer request gets in only when:
 *
 * - the token is valid here (`AccessTokenValidator`): signed by Rock Software
 *   ID (RS256 or ES256), for the audience set on the settings page, unexpired;
 * - its subject is a user linked to Rock Software ID (`external_id`), in the
 *   connection's required group, with access now (`UserAccess`), exactly as
 *   for a sign-in in the browser;
 * - it was issued after the user's sessions were last ended (a back-channel
 *   logout, or *End sessions* on the users page).
 *
 * Refusals: 401 with an empty body and `WWW-Authenticate: Bearer` (with
 * `error="invalid_token"` when a token was sent, and `resource_metadata` when
 * `RockSoftwareUsers.bearer.resourceMetadataUrl` is set) for a missing or
 * invalid token; 403 for a valid token whose user has no access; 429 for an
 * invalid token once its client address has sent `rateLimit` of them within
 * `rateWindow` seconds, and for a valid token once its subject has made
 * `subjectRateLimit` requests within `subjectRateWindow` seconds. Each
 * outcome dispatches `EVENT_ACCEPTED` or `EVENT_REFUSED`.
 *
 * A request let in carries the identity in the application's identity
 * attribute (`identity`, like a signed-in session), `ATTRIBUTE_BEARER` and
 * `ATTRIBUTE_IS_VIA_AGENT`. The plugin places this middleware right after the
 * Authentication middleware.
 */
class BearerTokenMiddleware implements MiddlewareInterface
{
    /** Request attribute: `['clientId' => ?string, 'scope' => list<string>, 'jti' => ?string, 'subject' => string]`. */
    public const ATTRIBUTE_BEARER = 'rockSoftwareUsers.bearer';

    /** Request attribute: true on a request signed in with an access token (an agent acting for the user). */
    public const ATTRIBUTE_IS_VIA_AGENT = 'rockSoftwareUsers.isViaAgent';

    /** Data: `user`, `clientId`, `scope`, `jti`, `subject`, `method`, `path`, `status` (the response's). */
    public const EVENT_ACCEPTED = 'RockSoftwareUsers.bearerAccepted';

    /** Data: `reason` (a `REASON_*`), `status`, `user`, `clientId`, `subject`, `jti`, `ipAddress`, `method`, `path`. */
    public const EVENT_REFUSED = 'RockSoftwareUsers.bearerRefused';

    public const REASON_MISSING_TOKEN = 'missing_token';
    public const REASON_INVALID_TOKEN = 'invalid_token';
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_TOKEN_REVOKED = 'token_revoked';
    public const REASON_UNKNOWN_USER = 'unknown_user';
    public const REASON_NOT_IN_GROUP = 'not_in_group';
    public const REASON_NO_ACCESS = 'no_access';
    public const REASON_RATE_LIMITED = 'rate_limited';
    public const REASON_SUBJECT_RATE_LIMITED = 'subject_rate_limited';
    public const REASON_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    private const ADDRESS_RATE_KEY = 'rock_software_users_bearer_failures_';

    private const SUBJECT_RATE_KEY = 'rock_software_users_bearer_subject_';

    /**
     * The bearer settings with their defaults.
     *
     * @return array{paths: list<string>, resourceMetadataUrl: string|null, rateLimit: int, rateWindow: int, subjectRateLimit: int, subjectRateWindow: int}
     */
    public static function config(): array
    {
        $config = Configure::read('RockSoftwareUsers.bearer');
        /** @var array{paths: list<string>, resourceMetadataUrl: string|null, rateLimit: int, rateWindow: int, subjectRateLimit: int, subjectRateWindow: int} */
        return array_replace(UsersPlugin::DEFAULTS['bearer'], is_array($config) ? $config : []);
    }

    /**
     * Whether a request is answered with an access token: its path is one of
     * `RockSoftwareUsers.bearer.paths` (a prefix: `/mcp` covers `/mcp` and
     * `/mcp/…`) and it carries no session cookie. The application's CSRF
     * middleware skips exactly these:
     * `(new CsrfProtectionMiddleware())->skipCheckCallback(BearerTokenMiddleware::isBearerRequest(...))`.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return bool
     */
    public static function isBearerRequest(ServerRequestInterface $request): bool
    {
        return self::onBearerPath($request->getUri()->getPath()) && !self::hasSessionCookie($request);
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!self::isBearerRequest($request)) {
            return $handler->handle($request);
        }
        $config = self::config();
        $context = [
            'ipAddress' => SignInHistory::clientIp($request),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
        ];

        $authorization = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer +([A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+) *$/i', $authorization, $match)) {
            if ($authorization === '') {
                return $this->refuse(401, self::REASON_MISSING_TOKEN, $context);
            }

            return $this->refuseInvalidToken($context, $config);
        }

        try {
            /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
            $settings = TableRegistry::getTableLocator()->get('RockSoftware/Users.Settings');
            $connection = $settings->enabled();
        } catch (Throwable $e) {
            Log::warning('Bearer token not checked: ' . $e->getMessage());

            return $this->refuse(401, self::REASON_NOT_CONFIGURED, $context);
        }
        $audience = trim((string)$connection?->bearer_audience);
        if ($connection === null || $audience === '') {
            return $this->refuse(401, self::REASON_NOT_CONFIGURED, $context);
        }

        try {
            $claims = (new AccessTokenValidator($connection))->validate($match[1], $audience);
        } catch (IdentityProviderUnavailableException $e) {
            // The provider is down: not the client's failure, so not counted.
            Log::warning('Bearer token not checked: ' . $e->getMessage());

            return $this->refuse(401, self::REASON_PROVIDER_UNAVAILABLE, $context);
        } catch (Throwable $e) {
            $reason = $e instanceof SignInException ? $e->getMessage() : get_class($e);
            Log::warning('Bearer token refused: ' . $reason);

            return $this->refuseInvalidToken($context, $config);
        }
        $context += ['subject' => $claims->subject, 'clientId' => $claims->clientId, 'jti' => $claims->jti];
        $subjectKey = self::SUBJECT_RATE_KEY . $claims->subject;
        if (!$this->count($subjectKey, $config['subjectRateLimit'], $config['subjectRateWindow'])) {
            return $this->refuse(429, self::REASON_SUBJECT_RATE_LIMITED, $context)
                ->withHeader('Retry-After', (string)$config['subjectRateWindow']);
        }

        $provisioner = new UserProvisioner();
        $user = $provisioner->findBySubject($claims->subject);
        if ($user === null) {
            return $this->refuse(403, self::REASON_UNKNOWN_USER, $context);
        }
        $context['user'] = $user;
        $group = $connection->required_group;
        if ($group !== null && $group !== '' && !in_array($group, $claims->groups(), true)) {
            return $this->refuse(403, self::REASON_NOT_IN_GROUP, $context);
        }
        if (!UserAccess::allows($user)) {
            return $this->refuse(403, self::REASON_NO_ACCESS, $context);
        }
        if ($this->revoked($provisioner, $user, $claims)) {
            return $this->refuse(401, self::REASON_TOKEN_REVOKED, $context);
        }

        $request = $this->signIn($request, $user, $claims);
        $status = 500;
        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            // After the handler, so an application whose listeners CakePHP
            // registers in `handle()` hears it too.
            EventManager::instance()->dispatch(new Event(self::EVENT_ACCEPTED, $this, [
                'user' => $user,
                'clientId' => $claims->clientId,
                'scope' => $claims->scopes,
                'jti' => $claims->jti,
                'subject' => $claims->subject,
                'method' => $context['method'],
                'path' => $context['path'],
                'status' => $status,
            ]));
        }
    }

    /**
     * Sets the identity the way a signed-in session does, through the
     * application's authentication service when there is one (its identity
     * class and attribute), and marks the request as an agent's.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param \RockSoftware\Users\Service\AccessTokenClaims $claims The token.
     * @return \Psr\Http\Message\ServerRequestInterface
     */
    private function signIn(
        ServerRequestInterface $request,
        EntityInterface $user,
        AccessTokenClaims $claims,
    ): ServerRequestInterface {
        $service = $request->getAttribute('authentication');
        $attribute = 'identity';
        $identity = null;
        if ($service instanceof AuthenticationService) {
            $attribute = $service->getIdentityAttribute();
            $identity = $service->buildIdentity($user);
        }
        if (!$identity instanceof IdentityInterface) {
            $identity = new Identity($user);
        }

        return $request
            ->withAttribute($attribute, $identity)
            ->withAttribute('authenticationResult', new Result($user, Result::SUCCESS))
            ->withAttribute(self::ATTRIBUTE_BEARER, [
                'clientId' => $claims->clientId,
                'scope' => $claims->scopes,
                'jti' => $claims->jti,
                'subject' => $claims->subject,
            ])
            ->withAttribute(self::ATTRIBUTE_IS_VIA_AGENT, true);
    }

    /**
     * Whether the token was issued before the user's sessions were last
     * ended, so it outlived them. A token without `iat` cannot prove it is
     * newer.
     *
     * @param \RockSoftware\Users\Service\UserProvisioner $provisioner The provisioner.
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param \RockSoftware\Users\Service\AccessTokenClaims $claims The token.
     * @return bool
     */
    private function revoked(UserProvisioner $provisioner, EntityInterface $user, AccessTokenClaims $claims): bool
    {
        $userId = (int)$user->get('id');
        /** @var \RockSoftware\Users\Model\Table\SessionEventsTable $events */
        $events = TableRegistry::getTableLocator()->get('RockSoftware/Users.SessionEvents');
        $moments = [
            $events->sessionsEndedAt($userId),
            $provisioner->identities()->findForUser($userId)?->sessions_valid_from,
        ];
        foreach ($moments as $moment) {
            if (
                $moment instanceof DateTimeInterface
                && ($claims->issuedAt === null || $claims->issuedAt < $moment->getTimestamp())
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bare refusal, and its event.
     *
     * @param int $status 401, 403 or 429.
     * @param string $reason A `REASON_*`.
     * @param array<string, mixed> $context What is known of the request.
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function refuse(int $status, string $reason, array $context): ResponseInterface
    {
        UsersPlugin::registerApplicationEvents();
        EventManager::instance()->dispatch(new Event(self::EVENT_REFUSED, $this, [
            'reason' => $reason,
            'status' => $status,
            'user' => $context['user'] ?? null,
            'clientId' => $context['clientId'] ?? null,
            'subject' => $context['subject'] ?? null,
            'jti' => $context['jti'] ?? null,
            'ipAddress' => $context['ipAddress'] ?? null,
            'method' => $context['method'] ?? null,
            'path' => $context['path'] ?? null,
        ]));

        $response = (new Response())->withStatus($status)->withHeader('Cache-Control', 'no-store');
        if ($status !== 401) {
            return $response;
        }

        return $response->withHeader('WWW-Authenticate', self::challenge($reason !== self::REASON_MISSING_TOKEN));
    }

    /**
     * The `WWW-Authenticate` value of a 401 (RFC 6750, RFC 9728).
     *
     * @param bool $invalidToken Whether a token was sent and refused.
     * @return string
     */
    public static function challenge(bool $invalidToken): string
    {
        $realm = '';
        try {
            $realm = (string)Settings::get(SettingsPlugin::APPLICATION_NAME);
        } catch (Throwable) {
            // The realm is cosmetic; a missing setting must not turn a 401 into a 500.
        }
        $parts = ['realm="' . self::quoted($realm) . '"'];
        if ($invalidToken) {
            $parts[] = 'error="invalid_token"';
        }
        $metadata = self::config()['resourceMetadataUrl'];
        if (is_string($metadata) && $metadata !== '') {
            $parts[] = 'resource_metadata="' . self::quoted($metadata) . '"';
        }

        return 'Bearer ' . implode(', ', $parts);
    }

    /**
     * Refuses an invalid token: counts it against the client address,
     * and answers 429 instead of 401 once the address has sent `rateLimit`
     * invalid tokens within `rateWindow` seconds. A valid token is never held
     * back by the failures of others at the same address.
     *
     * @param array<string, mixed> $context What is known of the request.
     * @param array{rateLimit: int, rateWindow: int} $config The settings.
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function refuseInvalidToken(array $context, array $config): ResponseInterface
    {
        $key = self::ADDRESS_RATE_KEY . (string)$context['ipAddress'];
        if (!$this->count($key, $config['rateLimit'], $config['rateWindow'])) {
            return $this->refuse(429, self::REASON_RATE_LIMITED, $context)
                ->withHeader('Retry-After', (string)$config['rateWindow']);
        }

        return $this->refuse(401, self::REASON_INVALID_TOKEN, $context);
    }

    /**
     * Counts one more request in a fixed window.
     *
     * @param string $name What is counted (an address, a subject).
     * @param int $limit Requests allowed per window.
     * @param int $window The window in seconds.
     * @return bool Whether this request is within the limit.
     */
    private function count(string $name, int $limit, int $window): bool
    {
        $cache = (string)Configure::read('RockSoftwareUsers.cacheConfig', 'default');
        $key = 'rock_software_users_bearer_rate_' . hash('sha256', $name);
        $now = time();
        $counter = Cache::read($key, $cache);
        if (!is_array($counter) || ($counter['start'] ?? 0) <= $now - $window) {
            $counter = ['start' => $now, 'count' => 0];
        }
        $counter['count']++;
        Cache::write($key, $counter, $cache);

        return $counter['count'] <= $limit;
    }

    /**
     * @param string $path The request path.
     * @return bool
     */
    private static function onBearerPath(string $path): bool
    {
        foreach ((array)self::config()['paths'] as $prefix) {
            if (!is_string($prefix) || $prefix === '' || !str_starts_with($prefix, '/')) {
                continue;
            }
            $prefix = rtrim($prefix, '/');
            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return bool
     */
    private static function hasSessionCookie(ServerRequestInterface $request): bool
    {
        $name = Configure::read('Session.cookie');
        if (!is_string($name) || $name === '') {
            $name = session_name();
        }

        return is_string($name) && $name !== '' && isset($request->getCookieParams()[$name]);
    }

    /**
     * @param string $value A header parameter value.
     * @return string It without quotes, backslashes or control characters.
     */
    private static function quoted(string $value): string
    {
        return (string)preg_replace('/["\\\\\x00-\x1f\x7f]/', '', $value);
    }
}
