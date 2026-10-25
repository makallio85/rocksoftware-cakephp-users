<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Core\Configure;
use Cake\Database\Schema\TableSchemaInterface;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use RockSoftware\Users\Model\Table\IdentitiesTable;
use RockSoftware\Users\Model\Table\UsersTable;

/**
 * Turns a verified identity into the application's user row.
 *
 * The plugin owns these columns of the application's `users` table (its
 * migration adds the missing ones): `email` (unique), `name`, `phone`,
 * `is_active`, `access_from`, `access_until`. A `password` column, when the
 * application keeps one, serves password sign-in. Everything else on the row
 * is the application's.
 *
 * Rock Software ID pushes people through SCIM (`Scim\ScimServer`), so a person
 * usually exists before their first sign-in. A sign-in still creates or links
 * the row when SCIM has not (an application not yet connected to SCIM): the
 * user is found through their identity's `external_id`, else matched on a
 * verified email address. Every sign-in refreshes the email and name.
 *
 * A user without access right now (`UserAccess`) is refused.
 *
 * Fields the application needs beyond these are filled by a listener on
 * `RockSoftwareUsers.beforeUserSave`.
 */
class UserProvisioner
{
    use LocatorAwareTrait;

    public const EVENT_BEFORE_USER_SAVE = 'RockSoftwareUsers.beforeUserSave';

    public const EMAIL = 'email';
    public const NAME = 'name';
    public const PHONE = 'phone';
    public const IS_ACTIVE = 'is_active';
    public const ACCESS_FROM = 'access_from';
    public const ACCESS_UNTIL = 'access_until';
    public const PASSWORD = 'password';

    /**
     * @param array<string, mixed> $claims Verified ID token claims.
     * @return \Cake\Datasource\EntityInterface
     */
    public function provision(array $claims): EntityInterface
    {
        $subject = (string)$claims['sub'];
        $email = $claims['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw new SignInException('The identity carries no email address.');
        }

        $users = $this->users();
        $identities = $this->identities();

        $identity = $identities->findByExternalId($subject);
        $user = $identity === null ? null : $this->findById($identity->user_id);
        if ($identity !== null && $user === null) {
            // The application deleted the user; the stale link goes with it.
            $identities->deleteOrFail($identity);
            $identity = null;
        }
        if ($user === null && ($claims['email_verified'] ?? false) === true) {
            $user = $this->findUnlinkedByEmail($email);
        }

        if ($user !== null) {
            UserAccess::check($user);
        }

        $isNew = $user === null;
        if ($isNew && !Configure::read('RockSoftwareUsers.createUsers')) {
            throw new SignInException(sprintf('No user matches %s and creating users is switched off.', $email));
        }
        $user ??= $users->newEmptyEntity();

        $name = $claims['name'] ?? null;
        $values = [
            self::EMAIL => $email,
            self::NAME => $this->fitName(is_string($name) && $name !== '' ? $name : $email),
        ];
        if ($isNew) {
            $values[self::IS_ACTIVE] = true;
            $values += $this->requiredDefaults($users->getSchema());
        }
        foreach ($values as $field => $value) {
            $user->set($field, $value, ['guard' => false]);
        }

        EventManager::instance()->dispatch(new Event(self::EVENT_BEFORE_USER_SAVE, $this, [
            'user' => $user,
            'claims' => $claims,
            'isNew' => $isNew,
        ]));

        $save = function () use ($users, $user, $identity, $identities, $subject) {
            // The application's validation describes its own forms (a password on
            // create, for one); this data comes from the provider. Its rules, such
            // as a unique email address, still apply.
            $users->saveOrFail($user, ['validate' => false]);

            $identity ??= $identities->newEmptyEntity();
            $identity->set('user_id', (int)$user->get('id'), ['guard' => false]);
            $identity->set('external_id', $subject, ['guard' => false]);
            $identity->set('synced_at', DateTime::now(), ['guard' => false]);
            $identities->saveOrFail($identity);

            return $user;
        };

        return $users->getConnection()->transactional($save);
    }

    /**
     * Creates a user with no link to the identity provider, as a first
     * sign-in would (the application's `RockSoftwareUsers.beforeUserSave`
     * listeners run, a required password gets an unusable value). For an
     * emergency sign-in on an empty development database; the first real
     * sign-in or SCIM push with the same address links it.
     *
     * @param string $email The email address.
     * @return \Cake\Datasource\EntityInterface
     */
    public function createUser(string $email): EntityInterface
    {
        $users = $this->users();
        $user = $users->newEmptyEntity();

        $values = [self::EMAIL => $email, self::NAME => $email, self::IS_ACTIVE => true];
        $values += $this->requiredDefaults($users->getSchema());
        foreach ($values as $field => $value) {
            $user->set($field, $value, ['guard' => false]);
        }

        EventManager::instance()->dispatch(new Event(self::EVENT_BEFORE_USER_SAVE, $this, [
            'user' => $user,
            'claims' => ['email' => $email, 'name' => $email],
            'isNew' => true,
        ]));

        return $users->saveOrFail($user, ['validate' => false]);
    }

    /**
     * A user no identity is linked to yet, by email address in any case.
     *
     * @param string $email The address.
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function findUnlinkedByEmail(string $email): ?EntityInterface
    {
        $users = $this->users();
        $linked = $this->identities()->find()->select(['user_id']);

        /** @var \Cake\Datasource\EntityInterface|null */
        return $users->find()
            ->where([
                'LOWER(' . $users->aliasField(self::EMAIL) . ')' => mb_strtolower($email),
                $users->aliasField('id') . ' NOT IN' => $linked,
            ])
            ->first();
    }

    /**
     * A user by email address in any case.
     *
     * @param string $email The address.
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function findByEmail(string $email): ?EntityInterface
    {
        $users = $this->users();

        /** @var \Cake\Datasource\EntityInterface|null */
        return $users->find()
            ->where(['LOWER(' . $users->aliasField(self::EMAIL) . ')' => mb_strtolower($email)])
            ->first();
    }

    /**
     * Finds the user a subject identifier belongs to.
     *
     * @param string $subject The provider's subject identifier.
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function findBySubject(string $subject): ?EntityInterface
    {
        $identity = $this->identities()->findByExternalId($subject);

        return $identity === null ? null : $this->findById($identity->user_id);
    }

    /**
     * @param int $id The user id.
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function findById(int $id): ?EntityInterface
    {
        $users = $this->users();

        /** @var \Cake\Datasource\EntityInterface|null */
        return $users->find()->where([$users->aliasField('id') => $id])->first();
    }

    /**
     * The application's users table: its own `UsersTable` class when it has
     * one (which may extend the plugin's), else the plugin's.
     *
     * @return \Cake\ORM\Table
     */
    public function users(): Table
    {
        $locator = $this->getTableLocator();
        if ($locator->exists('Users')) {
            return $locator->get('Users');
        }
        $appClass = Configure::read('App.namespace', 'App') . '\\Model\\Table\\UsersTable';

        return class_exists($appClass)
            ? $locator->get('Users')
            : $locator->get('Users', ['className' => UsersTable::class]);
    }

    /**
     * @return \RockSoftware\Users\Model\Table\IdentitiesTable
     */
    public function identities(): IdentitiesTable
    {
        /** @var \RockSoftware\Users\Model\Table\IdentitiesTable */
        return $this->fetchTable('RockSoftware/Users.Identities');
    }

    /**
     * The ids of `RockSoftwareUsers.systemUsers`: rows the application runs
     * jobs as (an application's system user), which SCIM never touches and the users
     * page never calls missing from Rock Software ID.
     *
     * @return array<int>
     */
    public static function systemUserIds(): array
    {
        return array_values(array_map('intval', (array)Configure::read('RockSoftwareUsers.systemUsers', [])));
    }

    /**
     * A name cut to the length of the application's `users.name`: a person's
     * name must never stop them being saved.
     *
     * @param string $name The name.
     * @return string
     */
    public function fitName(string $name): string
    {
        $length = $this->users()->getSchema()->getColumn(self::NAME)['length'] ?? null;

        return is_int($length) && $length > 0 ? mb_substr($name, 0, $length) : $name;
    }

    /**
     * The name shown for a user: the name, else the email address.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return string
     */
    public static function displayName(EntityInterface $user): string
    {
        $name = $user->get(self::NAME);

        return is_string($name) && $name !== '' ? $name : (string)$user->get(self::EMAIL);
    }

    /**
     * A value for every column a new row cannot be inserted without, other than
     * the ones written above: a `password` gets an unusable value, which no
     * password check ever matches. Anything else is the application's to fill,
     * on `RockSoftwareUsers.beforeUserSave`.
     *
     * @param \Cake\Database\Schema\TableSchemaInterface $schema The users table.
     * @return array<string, mixed>
     */
    public function requiredDefaults(TableSchemaInterface $schema): array
    {
        $values = [];
        $definition = $schema->getColumn(self::PASSWORD);
        if ($definition !== null && !$definition['null'] && $definition['default'] === null) {
            $values[self::PASSWORD] = '!rsu:' . bin2hex(random_bytes(24));
        }

        return $values;
    }
}
