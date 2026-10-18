<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Psr\Http\Message\ServerRequestInterface;
use RockSoftware\Users\Model\Entity\SessionEventType;
use Throwable;

/**
 * Writes the sign-in history. A row that cannot be written is logged and
 * skipped: the history never stops anyone signing in or out.
 */
class SignInHistory
{
    use LocatorAwareTrait;

    /**
     * @param string $type One of the `SessionEventType` value constants.
     * @param array{user_id?: int|null, email?: string|null, method?: string|null, actor_user_id?: int|null} $data What is known.
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request, for the client's address.
     * @return void
     */
    public function record(string $type, array $data = [], ?ServerRequestInterface $request = null): void
    {
        try {
            /** @var \RockSoftware\Users\Model\Table\SessionEventsTable $events */
            $events = $this->fetchTable('RockSoftware/Users.SessionEvents');
            $events->record($type, $data + [
                'ip_address' => self::clientIp($request),
                'user_agent' => self::userAgent($request),
            ]);
            if ($type === SessionEventType::SIGNED_IN) {
                /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
                $settings = $this->fetchTable('RockSoftware/Users.Settings');
                $events->prune($settings->sessionLimits()['retention_days']);
            }
        } catch (Throwable $e) {
            Log::error(sprintf('Sign-in history not written (%s): %s', $type, $e->getMessage()));
        }
    }

    /**
     * The client's address. Applications run behind a proxy (Traefik, and
     * sometimes nginx in the container), so the connection comes from a
     * private address and the client is in `X-Forwarded-For`. Read from the
     * right, the first public address there is the one our own proxy added;
     * anything further left the client could have written itself. A
     * connection from a public address is the client.
     *
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request.
     * @return string|null
     */
    public static function clientIp(?ServerRequestInterface $request): ?string
    {
        if ($request === null) {
            return null;
        }
        $remote = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($remote !== '' && !self::isPrivate($remote)) {
            return mb_substr($remote, 0, 45);
        }
        $forwarded = array_reverse(array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For'))));
        foreach ($forwarded as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false && !self::isPrivate($candidate)) {
                return $candidate;
            }
        }

        return $remote === '' ? null : mb_substr($remote, 0, 45);
    }

    /**
     * The browser, as its User-Agent says, cut to the column.
     *
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request.
     * @return string|null
     */
    public static function userAgent(?ServerRequestInterface $request): ?string
    {
        $agent = $request === null ? '' : trim($request->getHeaderLine('User-Agent'));

        return $agent === '' ? null : mb_substr($agent, 0, 255);
    }

    /**
     * @param string $ip An address.
     * @return bool Whether it is private, loopback or reserved.
     */
    private static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
