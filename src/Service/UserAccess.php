<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use DateTimeInterface;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\UsersPlugin;

/**
 * Whether a user may use the application right now. Every way in asks here:
 * Rock Software ID, password sign-in, emergency links, the session check and
 * access tokens (`BearerTokenMiddleware`).
 *
 * - `is_active` off: access was revoked (by Rock Software ID, or on the
 *   server before 3.0).
 * - `access_from` in the future: access has not started yet.
 * - `access_until` passed: access has ended.
 *
 * The dates arrive through SCIM; a null date sets no limit. They are stored
 * through the ORM like every other datetime of the application and compared
 * with the current moment.
 */
final class UserAccess
{
    public const ACTIVE = 'active';
    public const NOT_STARTED = 'not_started';
    public const ENDED = 'ended';
    public const REVOKED = 'revoked';

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param \DateTimeInterface|null $now The moment; now by default.
     * @return string One of the state constants.
     */
    public static function state(EntityInterface $user, ?DateTimeInterface $now = null): string
    {
        if ($user->has(UserProvisioner::IS_ACTIVE) && !$user->get(UserProvisioner::IS_ACTIVE)) {
            return self::REVOKED;
        }
        $now ??= DateTime::now();
        $from = $user->get(UserProvisioner::ACCESS_FROM);
        if ($from instanceof DateTimeInterface && $from > $now) {
            return self::NOT_STARTED;
        }
        $until = $user->get(UserProvisioner::ACCESS_UNTIL);
        if ($until instanceof DateTimeInterface && $until <= $now) {
            return self::ENDED;
        }

        return self::ACTIVE;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return bool
     */
    public static function allows(EntityInterface $user): bool
    {
        return self::state($user) === self::ACTIVE;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return void
     * @throws \RockSoftware\Users\Service\UserInactiveException When the user has no access now.
     */
    public static function check(EntityInterface $user): void
    {
        $state = self::state($user);
        if ($state !== self::ACTIVE) {
            throw new UserInactiveException(
                sprintf('%s has no access to this application (%s).', (string)$user->get('email'), $state),
                $state,
                $user,
            );
        }
    }

    /**
     * The sign-in history type for a refusal.
     *
     * @param string $state One of the state constants other than `ACTIVE`.
     * @return string
     */
    public static function sessionEventType(string $state): string
    {
        return match ($state) {
            self::NOT_STARTED => SessionEventType::ACCESS_NOT_STARTED,
            self::ENDED => SessionEventType::ACCESS_ENDED,
            default => SessionEventType::USER_INACTIVE,
        };
    }

    /**
     * What the person is told when they are refused.
     *
     * @param string $state One of the state constants other than `ACTIVE`.
     * @param \Cake\Datasource\EntityInterface|null $user The user, for the start date.
     * @return string
     */
    public static function refusalMessage(string $state, ?EntityInterface $user = null): string
    {
        $from = $user?->get(UserProvisioner::ACCESS_FROM);

        return match ($state) {
            self::NOT_STARTED => $from instanceof DateTimeInterface
                ? __d('rock_software_users', 'Your access to this application starts on {0}.', self::format($from))
                : __d('rock_software_users', 'Your access to this application has not started yet.'),
            self::ENDED => __d('rock_software_users', 'Your access to this application has ended.'),
            default => __d('rock_software_users', 'You have no access to this application.'),
        };
    }

    /**
     * A moment as people read it: day, month, year and time in
     * the display time zone (`UsersPlugin::displayTimezone()`).
     *
     * @param \DateTimeInterface $moment The moment.
     * @return string
     */
    public static function format(DateTimeInterface $moment): string
    {
        return DateTime::parse($moment)->setTimezone(UsersPlugin::displayTimezone())->format('j.n.Y H.i');
    }
}
