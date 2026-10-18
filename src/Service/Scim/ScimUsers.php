<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service\Scim;

use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeInterface;
use Exception;
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Model\Entity\Identity;
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Model\Table\AccessChangesTable;
use RockSoftware\Users\Model\Table\UserEmailsTable;
use RockSoftware\Users\Service\AccessManager;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * SCIM `/Users`: Rock Software ID creates people here before their first
 * sign-in, keeps their details current, and takes their access away. A user
 * row is never deleted: `DELETE` and `active: false` revoke access
 * (`AccessManager`), and a later push with `active: true` gives it back to the
 * same row.
 *
 * A pushed user is matched by `externalId` (the Rock Software ID `uid`, equal
 * to the OIDC `sub`), else by email address to a row nobody is linked to yet,
 * and created only when neither matches. The SCIM `id` is `users.id`.
 */
class ScimUsers
{
    use LocatorAwareTrait;

    public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const ACCESS_SCHEMA = 'urn:rocksoftware:params:scim:schemas:extension:access:2.0:User';

    /**
     * @param \RockSoftware\Users\Service\AccessManager $access Gives and takes access.
     * @param string $baseUrl The endpoint's own address, for `meta.location`.
     */
    public function __construct(private AccessManager $access, private string $baseUrl)
    {
    }

    /**
     * `POST /Users`.
     *
     * @param array<string, mixed> $payload The SCIM user.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    public function create(array $payload): ScimResponse
    {
        $person = ScimPerson::fromPayload($payload);
        if (is_string($person)) {
            return ScimResponse::error(400, $person, 'invalidValue');
        }
        $provisioner = new UserProvisioner();
        $identities = $provisioner->identities();

        $identity = $identities->findByExternalId($person->externalId);
        $user = $identity === null ? null : $provisioner->findById($identity->user_id);
        if ($user === null) {
            $user = $provisioner->findUnlinkedByEmail($person->email);
        }
        if ($user !== null) {
            return $this->apply($user, $person, $payload, 201);
        }
        if ($provisioner->findByEmail($person->email) !== null) {
            $this->access->alert(
                AccessManager::ALERT_EMAIL_CONFLICT,
                sprintf(
                    'Rock Software ID tried to add %s, but another user here already has that address.',
                    $person->email,
                ),
            );

            return ScimResponse::error(409, 'Another user already has this email address.', 'uniqueness');
        }

        $users = $provisioner->users();
        $user = $users->newEmptyEntity();
        $values = [
            UserProvisioner::EMAIL => $person->email,
            UserProvisioner::NAME => $provisioner->fitName($person->name),
            UserProvisioner::PHONE => $person->phone,
            UserProvisioner::IS_ACTIVE => $person->active,
            UserProvisioner::ACCESS_FROM => $person->accessFrom,
            UserProvisioner::ACCESS_UNTIL => $person->accessUntil,
        ] + $provisioner->requiredDefaults($users->getSchema());
        foreach ($values as $field => $value) {
            $user->set($field, $value, ['guard' => false]);
        }
        EventManager::instance()->dispatch(new Event(UserProvisioner::EVENT_BEFORE_USER_SAVE, $this, [
            'user' => $user,
            'claims' => ['email' => $person->email, 'name' => $person->name, 'sub' => $person->externalId],
            'isNew' => true,
        ]));

        $users->getConnection()->transactional(function () use ($users, $user, $person, $identities): void {
            $users->saveOrFail($user, ['validate' => false]);
            $identity = $identities->newEmptyEntity();
            foreach (
                [
                    'user_id' => (int)$user->get('id'),
                    'external_id' => $person->externalId,
                    'user_name' => $person->userName,
                    'synced_at' => DateTime::now(),
                ] as $field => $value
            ) {
                $identity->set($field, $value, ['guard' => false]);
            }
            $identities->saveOrFail($identity);
            $this->userEmails()->replaceFor((int)$user->get('id'), $person->otherEmails);
        });
        if ($person->active) {
            $this->accessChanges()->record((int)$user->get('id'), AccessChangeType::GRANTED, [
                'detail' => $this->dateDetail($person),
            ]);
            EventManager::instance()->dispatch(new Event(AccessManager::EVENT_GRANTED, $this->access, [
                'user' => $user,
                'actorUserId' => null,
            ]));
        }

        return new ScimResponse(201, $this->resource($user, $payload));
    }

    /**
     * `PUT /Users/{id}`.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param array<string, mixed> $payload The SCIM user.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    public function replace(EntityInterface $user, array $payload): ScimResponse
    {
        $person = ScimPerson::fromPayload($payload);
        if (is_string($person)) {
            return ScimResponse::error(400, $person, 'invalidValue');
        }

        return $this->apply($user, $person, $payload, 200);
    }

    /**
     * `DELETE /Users/{id}`: access goes; the row stays. The person's group
     * memberships go too, so pushing them again starts clean.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    public function delete(EntityInterface $user): ScimResponse
    {
        $this->fetchTable('RockSoftware/Users.ScimGroupMembers')->deleteAll(['user_id' => (int)$user->get('id')]);
        $this->access->requestRevocation($user, RevocationType::REMOVED);

        return new ScimResponse(204);
    }

    /**
     * The SCIM representation of a user.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param array<string, mixed> $echo What Rock Software ID sent, answered back so
     *   its next full sync sees nothing to change; empty for a read.
     * @return array<string, mixed>
     */
    public function resource(EntityInterface $user, array $echo = []): array
    {
        $identity = (new UserProvisioner())->identities()->findForUser((int)$user->get('id'));
        $email = (string)$user->get(UserProvisioner::EMAIL);
        $name = UserProvisioner::displayName($user);
        $phone = $user->get(UserProvisioner::PHONE);
        $from = $user->get(UserProvisioner::ACCESS_FROM);
        $until = $user->get(UserProvisioner::ACCESS_UNTIL);

        $resource = $echo !== [] ? $echo : [
            'schemas' => [self::SCHEMA, self::ACCESS_SCHEMA],
            'userName' => $identity?->user_name ?? $email,
            'name' => ['formatted' => $name],
            'displayName' => $name,
            'emails' => [['value' => $email, 'primary' => true]],
            self::ACCESS_SCHEMA => [
                'accessFrom' => $from instanceof DateTime ? $from->toIso8601String() : null,
                'accessUntil' => $until instanceof DateTime ? $until->toIso8601String() : null,
            ],
        ];
        if ($echo === [] && is_string($phone) && $phone !== '') {
            $resource['phoneNumbers'] = [['value' => $phone, 'type' => 'mobile', 'primary' => true]];
        }
        $resource['id'] = (string)$user->get('id');
        if ($identity !== null) {
            $resource['externalId'] = $identity->external_id;
        }
        // Answering a write, `active` is what Rock Software ID sent: Authentik
        // keeps the answer as the state it last wrote, and a held removal
        // answered with the real `true` made the later "active again" look
        // like no change, so it was never sent.
        $resource['active'] = $echo !== [] && array_key_exists('active', $echo)
            ? !in_array($echo['active'], [false, 'false', 'False'], true)
            : (bool)$user->get(UserProvisioner::IS_ACTIVE);
        $created = $user->get('created');
        $modified = $user->get('modified');
        $resource['meta'] = array_filter([
            'resourceType' => 'User',
            'created' => $created instanceof DateTime ? $created->toIso8601String() : null,
            'lastModified' => $modified instanceof DateTime ? $modified->toIso8601String() : null,
            'location' => $this->baseUrl . '/Users/' . $user->get('id'),
        ]);

        return $resource;
    }

    /**
     * Writes a pushed person onto an existing row.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param \RockSoftware\Users\Service\Scim\ScimPerson $person The pushed person.
     * @param array<string, mixed> $payload The SCIM user, to answer back.
     * @param int $status 201 for POST, 200 for PUT.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    private function apply(EntityInterface $user, ScimPerson $person, array $payload, int $status): ScimResponse
    {
        $provisioner = new UserProvisioner();
        $userId = (int)$user->get('id');
        if (in_array($userId, UserProvisioner::systemUserIds(), true)) {
            return ScimResponse::error(409, 'This user is a system user of the application.', 'uniqueness');
        }
        $identities = $provisioner->identities();
        $identity = $identities->findForUser($userId);
        if ($identity !== null && $identity->external_id !== $person->externalId) {
            return ScimResponse::error(409, 'This user is linked to another Rock Software ID account.', 'uniqueness');
        }

        $conflict = false;
        $other = $provisioner->findByEmail($person->email);
        if ($other !== null && (int)$other->get('id') !== $userId) {
            $conflict = true;
        }

        $changed = [];
        $values = [
            UserProvisioner::NAME => $provisioner->fitName($person->name),
            UserProvisioner::PHONE => $person->phone,
            UserProvisioner::ACCESS_FROM => $person->accessFrom,
            UserProvisioner::ACCESS_UNTIL => $person->accessUntil,
        ];
        if (!$conflict) {
            $values[UserProvisioner::EMAIL] = $person->email;
        }
        foreach ($values as $field => $value) {
            if (!$this->same($user->get($field), $value)) {
                $changed[] = $field;
                $user->set($field, $value, ['guard' => false]);
            }
        }

        $users = $provisioner->users();
        $users->getConnection()->transactional(function () use (
            $users,
            $user,
            $identities,
            $identity,
            $person,
            $userId,
            $conflict,
            $changed,
        ): void {
            if ($changed !== []) {
                $users->saveOrFail($user, ['validate' => false]);
            }
            $identity ??= $identities->newEmptyEntity();
            $wasPending = $identity instanceof Identity ? $identity->get('pending_email') : null;
            foreach (
                [
                    'user_id' => $userId,
                    'external_id' => $person->externalId,
                    'user_name' => $person->userName,
                    'pending_email' => $conflict ? $person->email : null,
                    'synced_at' => DateTime::now(),
                ] as $field => $value
            ) {
                $identity->set($field, $value, ['guard' => false]);
            }
            $identities->saveOrFail($identity);
            if ($this->userEmails()->replaceFor($userId, $person->otherEmails)) {
                $changed[] = 'other_emails';
            }
            if ($changed !== []) {
                $this->accessChanges()->record($userId, AccessChangeType::DETAILS_UPDATED, [
                    'detail' => implode(', ', $changed),
                ]);
            }
            if ($conflict && $wasPending !== $person->email) {
                $this->accessChanges()->record($userId, AccessChangeType::EMAIL_CONFLICT, ['detail' => $person->email]);
            }
        });

        if ($conflict) {
            $this->access->alert(
                AccessManager::ALERT_EMAIL_CONFLICT,
                sprintf(
                    'Rock Software ID changed the email address of %s to %s, but another user here already has it.',
                    (string)$user->get(UserProvisioner::EMAIL),
                    $person->email,
                ),
                $user,
            );
        }

        if ($person->active) {
            $this->access->grant($user);
        } else {
            $this->access->requestRevocation($user, RevocationType::DEACTIVATED);
        }

        if ($conflict) {
            return ScimResponse::error(409, 'Another user already has this email address.', 'uniqueness');
        }

        return new ScimResponse($status, $this->resource($user, $payload));
    }

    /**
     * @param \RockSoftware\Users\Service\Scim\ScimPerson $person The pushed person.
     * @return string|null
     */
    private function dateDetail(ScimPerson $person): ?string
    {
        return $person->accessFrom === null ? null : 'from ' . $person->accessFrom->toIso8601String();
    }

    /**
     * @param mixed $current The stored value.
     * @param mixed $new The pushed value.
     * @return bool
     */
    private function same(mixed $current, mixed $new): bool
    {
        if ($current instanceof DateTimeInterface || $new instanceof DateTimeInterface) {
            try {
                return $current instanceof DateTimeInterface && $new instanceof DateTimeInterface
                    && $current->getTimestamp() === $new->getTimestamp();
            } catch (Exception) {
                return false;
            }
        }

        return $current === $new;
    }

    /**
     * @return \RockSoftware\Users\Model\Table\UserEmailsTable
     */
    private function userEmails(): UserEmailsTable
    {
        /** @var \RockSoftware\Users\Model\Table\UserEmailsTable */
        return $this->fetchTable('RockSoftware/Users.UserEmails');
    }

    /**
     * @return \RockSoftware\Users\Model\Table\AccessChangesTable
     */
    private function accessChanges(): AccessChangesTable
    {
        /** @var \RockSoftware\Users\Model\Table\AccessChangesTable */
        return $this->fetchTable('RockSoftware/Users.AccessChanges');
    }
}
