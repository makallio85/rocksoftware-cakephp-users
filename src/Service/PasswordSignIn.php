<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Core\Configure;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use RockSoftware\Users\Model\Entity\SessionEventType;

/**
 * The sign-in page's email and password form, checked against the
 * application's own users table.
 *
 * Offered while the application declares passwords (`RockSoftwareUsers.passwordSignIn`),
 * its users table has the password column (`RockSoftwareUsers.fields.password`) and the
 * switch on the settings page is on. Hashes are CakePHP's default, so the
 * passwords an application already has keep working; an outdated hash is
 * replaced on the next sign-in.
 *
 * Refused attempts are counted from the sign-in history: after
 * `MAX_FAILURES_PER_EMAIL` for one address, or `MAX_FAILURES_PER_IP` from
 * one client, within `WINDOW_MINUTES`, the form refuses without checking.
 */
class PasswordSignIn
{
    use LocatorAwareTrait;

    public const MAX_FAILURES_PER_EMAIL = 5;
    public const MAX_FAILURES_PER_IP = 20;
    public const WINDOW_MINUTES = 15;
    public const MIN_LENGTH = 12;

    /** A hash of a random string, checked when no user has the address. */
    private const TIMING_HASH = '$2y$12$r9c/8ZoIzvUUC6fGpt5ydeRKcU.JldqjnC2NZciZc3Am5PhBohx/u';

    /**
     * Whether the sign-in page offers the form now.
     *
     * @return bool
     */
    public function available(): bool
    {
        if (Configure::read('RockSoftwareUsers.passwordSignIn') !== true) {
            return false;
        }
        if (!(new UserProvisioner())->users()->getSchema()->hasColumn(UserProvisioner::PASSWORD)) {
            return false;
        }
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');

        return $settings->passwordSignInAllowed();
    }

    /**
     * Checks an email address and password.
     *
     * @param string $email The address typed.
     * @param string $password The password typed.
     * @param string|null $ip The client's address.
     * @return array{result: string, user: \Cake\Datasource\EntityInterface|null}
     *   `result` is `SessionEventType::SIGNED_IN` or the refusal.
     */
    public function attempt(string $email, string $password, ?string $ip): array
    {
        $email = trim($email);
        if (!$this->available()) {
            return ['result' => SessionEventType::PASSWORD_SIGN_IN_OFF, 'user' => null];
        }
        /** @var \RockSoftware\Users\Model\Table\SessionEventsTable $events */
        $events = $this->fetchTable('RockSoftware/Users.SessionEvents');
        $failures = $events->recentPasswordFailures($email, $ip, self::WINDOW_MINUTES);
        if ($failures['email'] >= self::MAX_FAILURES_PER_EMAIL || $failures['ip'] >= self::MAX_FAILURES_PER_IP) {
            return ['result' => SessionEventType::THROTTLED, 'user' => $this->findUser($email)];
        }

        $hasher = new DefaultPasswordHasher();
        $user = $email === '' ? null : $this->findUser($email);
        $column = UserProvisioner::PASSWORD;
        if ($user === null) {
            // The same work as a real check, so the answer's timing does not
            // tell which addresses have an account.
            $hasher->check($password, self::TIMING_HASH);

            return ['result' => SessionEventType::UNKNOWN_EMAIL, 'user' => null];
        }
        $hash = (string)$user->get($column);
        if ($password === '' || !$hasher->check($password, $hash)) {
            return ['result' => SessionEventType::WRONG_PASSWORD, 'user' => $user];
        }
        $state = UserAccess::state($user);
        if ($state !== UserAccess::ACTIVE) {
            return ['result' => UserAccess::sessionEventType($state), 'user' => $user];
        }
        if ($hasher->needsRehash($hash)) {
            $user->set($column, $hasher->hash($password), ['guard' => false]);
            (new UserProvisioner())->users()->saveOrFail($user, ['validate' => false]);
        }

        return ['result' => SessionEventType::SIGNED_IN, 'user' => $user];
    }

    /**
     * Sets a user's password (`bin/cake rock_software_users set_password`).
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param string $password The new password, at least `MIN_LENGTH` characters.
     * @return void
     */
    public function setPassword(EntityInterface $user, string $password): void
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new SignInException(sprintf('The password must be at least %d characters.', self::MIN_LENGTH));
        }
        $column = UserProvisioner::PASSWORD;
        $users = (new UserProvisioner())->users();
        if (!$users->getSchema()->hasColumn($column)) {
            throw new SignInException('The users table has no password column.');
        }
        $user->set($column, (new DefaultPasswordHasher())->hash($password), ['guard' => false]);
        $users->saveOrFail($user, ['validate' => false]);
    }

    /**
     * The user with this address, compared without regard to case.
     *
     * @param string $email The address.
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function findUser(string $email): ?EntityInterface
    {
        $users = (new UserProvisioner())->users();
        $field = $users->aliasField(UserProvisioner::EMAIL);

        /** @var \Cake\Datasource\EntityInterface|null */
        return $users->find()
            ->where(fn(QueryExpression $exp, SelectQuery $query) => $exp->eq(
                $query->func()->lower([$field => 'identifier']),
                mb_strtolower(trim($email)),
            ))
            ->first();
    }
}
