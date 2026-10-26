<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use DateTimeInterface;
use RockSoftware\Settings\Settings;
use RockSoftware\Settings\SettingsPlugin;
use RockSoftware\Users\Model\Entity\AccessChange;
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Model\Entity\HeldRevocation;
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Model\Table\AccessChangesTable;
use RockSoftware\Users\Model\Table\HeldRevocationsTable;
use Throwable;

/**
 * Gives and takes a person's access to this application. Rows are never
 * deleted: revoking turns `users.is_active` off, which every way in refuses
 * (`UserAccess`) and which ends the person's sessions on their next request;
 * the row, its history and the application's permissions stay, so a person
 * who comes back gets them again.
 *
 * **The mass-removal stop.** When the revocations within
 * `HOLD_WINDOW_MINUTES` would reach `HOLD_SHARE` of the application's active
 * users, a revocation is held instead (`rock_software_users_held_revocations`)
 * and an alert raised; so is every further one while any is held. A person
 * approves or rejects them on the users page or with
 * `bin/cake rock_software_users revocations`. Rock Software ID itself refuses a
 * held person's new sign-ins meanwhile. In an application with one user, that
 * user's removal always waits.
 *
 * Events, for the application to act on:
 *
 * - `RockSoftwareUsers.accessGranted` — data: `user`, `actorUserId`
 * - `RockSoftwareUsers.accessRevoked` — data: `user`, `revocation` (a
 *   `RevocationType` value), `actorUserId`
 * - `RockSoftwareUsers.alertRaised` — data: `alert` (`ALERT_*`), `message`,
 *   `user` (or null), `count`. The application sends it on with its own
 *   mailer; `RockSoftwareUsers.alertWebhookUrl`, when set, also receives it
 *   as JSON.
 */
class AccessManager
{
    use LocatorAwareTrait;

    public const EVENT_GRANTED = 'RockSoftwareUsers.accessGranted';
    public const EVENT_REVOKED = 'RockSoftwareUsers.accessRevoked';
    public const EVENT_ALERT = 'RockSoftwareUsers.alertRaised';

    public const ALERT_REVOCATIONS_HELD = 'revocations_held';
    public const ALERT_EMAIL_CONFLICT = 'email_conflict';

    public const HOLD_WINDOW_MINUTES = 15;
    public const HOLD_SHARE = 0.5;

    /**
     * @param \Cake\Http\Client|null $http For the alert webhook; injected by tests.
     */
    public function __construct(private ?Client $http = null)
    {
    }

    /**
     * Gives access (again). A revocation waiting for the user is dropped:
     * Rock Software ID has given the person back.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param int|null $actorUserId Who did it; null for Rock Software ID.
     * @return bool Whether access changed.
     */
    public function grant(EntityInterface $user, ?int $actorUserId = null): bool
    {
        $userId = (int)$user->get('id');
        $held = $this->heldRevocations()->pendingFor($userId);
        if ($held !== null) {
            $this->resolve(
                $held,
                AccessChangeType::REVOCATION_REJECTED,
                $actorUserId,
                'Rock Software ID gave access back',
            );
        }
        if ((bool)$user->get(UserProvisioner::IS_ACTIVE)) {
            return false;
        }
        $user->set(UserProvisioner::IS_ACTIVE, true, ['guard' => false]);
        $this->users()->saveOrFail($user, ['validate' => false]);
        $this->accessChanges()->record($userId, AccessChangeType::GRANTED, ['actor_user_id' => $actorUserId]);
        $this->dispatch(self::EVENT_GRANTED, ['user' => $user, 'actorUserId' => $actorUserId]);

        return true;
    }

    /**
     * Rock Software ID asks to take access away. The mass-removal stop may
     * hold it; system users are never touched.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param string $revocation One of the `RevocationType` value constants.
     * @return string `revoked`, `held` or `unchanged`.
     */
    public function requestRevocation(EntityInterface $user, string $revocation): string
    {
        $userId = (int)$user->get('id');
        $isSystemUser = in_array($userId, UserProvisioner::systemUserIds(), true);
        if ($isSystemUser || !(bool)$user->get(UserProvisioner::IS_ACTIVE)) {
            return 'unchanged';
        }
        if ($this->heldRevocations()->pendingFor($userId) !== null) {
            return 'held';
        }
        if ($this->mustHold()) {
            $held = $this->heldRevocations()->newEmptyEntity();
            $held->set('user_id', $userId, ['guard' => false]);
            /** @var \RockSoftware\Users\Model\Table\RevocationTypesTable $types */
            $types = $this->fetchTable('RockSoftware/Users.RevocationTypes');
            $held->set('revocation_type_id', $types->idFor($revocation), ['guard' => false]);
            $this->heldRevocations()->saveOrFail($held);
            $this->accessChanges()->record($userId, AccessChangeType::REVOCATION_HELD, ['revocation' => $revocation]);
            $count = count($this->heldRevocations()->pending());
            $this->alert(
                self::ALERT_REVOCATIONS_HELD,
                sprintf(
                    'Removing access is on hold for %d people: Rock Software ID asked to remove at least half of '
                    . "this application's users within %d minutes. Approve or reject on the users page.",
                    $count,
                    self::HOLD_WINDOW_MINUTES,
                ),
                $user,
                $count,
            );

            return 'held';
        }
        $this->revoke($user, $revocation, null);

        return 'revoked';
    }

    /**
     * Takes access away now.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param string $revocation One of the `RevocationType` value constants.
     * @param int|null $actorUserId Who decided; null for Rock Software ID.
     * @return \RockSoftware\Users\Model\Entity\AccessChange
     */
    public function revoke(
        EntityInterface $user,
        string $revocation,
        ?int $actorUserId,
    ): AccessChange {
        $user->set(UserProvisioner::IS_ACTIVE, false, ['guard' => false]);
        $this->users()->saveOrFail($user, ['validate' => false]);
        $change = $this->accessChanges()->record((int)$user->get('id'), AccessChangeType::REVOKED, [
            'revocation' => $revocation,
            'actor_user_id' => $actorUserId,
        ]);
        $this->dispatch(self::EVENT_REVOKED, [
            'user' => $user,
            'revocation' => $revocation,
            'actorUserId' => $actorUserId,
        ]);

        return $change;
    }

    /**
     * A person approves a held revocation: access goes now.
     *
     * @param \RockSoftware\Users\Model\Entity\HeldRevocation $held The held revocation.
     * @param int|null $actorUserId Who decided; null on the command line.
     * @return void
     */
    public function approve(HeldRevocation $held, ?int $actorUserId): void
    {
        $user = (new UserProvisioner())->findById($held->user_id);
        $revocation = $held->revocation_type->value ?? RevocationType::REMOVED;
        $this->resolve($held, AccessChangeType::REVOCATION_APPROVED, $actorUserId);
        if ($user !== null && (bool)$user->get(UserProvisioner::IS_ACTIVE)) {
            $this->revoke($user, $revocation, $actorUserId);
        }
    }

    /**
     * A person rejects a held revocation: access stays. If Rock Software ID
     * still means it, its next full sync asks again.
     *
     * @param \RockSoftware\Users\Model\Entity\HeldRevocation $held The held revocation.
     * @param int|null $actorUserId Who decided; null on the command line.
     * @return void
     */
    public function reject(HeldRevocation $held, ?int $actorUserId): void
    {
        $this->resolve($held, AccessChangeType::REVOCATION_REJECTED, $actorUserId);
    }

    /**
     * Gives one person access back from the server, when a mistake or a bug
     * took it: active again and inside their dates. The next push from Rock
     * Software ID overrules it.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return void
     */
    public function restore(EntityInterface $user): void
    {
        $userId = (int)$user->get('id');
        $held = $this->heldRevocations()->pendingFor($userId);
        if ($held !== null) {
            $this->resolve($held, AccessChangeType::REVOCATION_REJECTED, null, 'Access restored on the server');
        }
        $now = DateTime::now();
        $from = $user->get(UserProvisioner::ACCESS_FROM);
        $until = $user->get(UserProvisioner::ACCESS_UNTIL);
        $user->set(UserProvisioner::IS_ACTIVE, true, ['guard' => false]);
        if ($from instanceof DateTimeInterface && $from > $now) {
            $user->set(UserProvisioner::ACCESS_FROM, null, ['guard' => false]);
        }
        if ($until instanceof DateTimeInterface && $until <= $now) {
            $user->set(UserProvisioner::ACCESS_UNTIL, null, ['guard' => false]);
        }
        $this->users()->saveOrFail($user, ['validate' => false]);
        $this->accessChanges()->record($userId, AccessChangeType::ACCESS_RESTORED);
        $this->dispatch(self::EVENT_GRANTED, ['user' => $user, 'actorUserId' => null]);
    }

    /**
     * Raises an alert: the `alertRaised` event, and the webhook when one is
     * set. Never throws.
     *
     * @param string $alert One of the `ALERT_*` constants.
     * @param string $message For the person who has to act; no personal data
     *   beyond the email address of the user concerned.
     * @param \Cake\Datasource\EntityInterface|null $user The user concerned.
     * @param int $count How many are concerned.
     * @return void
     */
    public function alert(string $alert, string $message, ?EntityInterface $user = null, int $count = 1): void
    {
        Log::warning('Rock Software ID users: ' . $message);
        $this->dispatch(self::EVENT_ALERT, [
            'alert' => $alert,
            'message' => $message,
            'user' => $user,
            'count' => $count,
        ]);

        $url = Configure::read('RockSoftwareUsers.alertWebhookUrl');
        if (!is_string($url) || !str_starts_with($url, 'https://')) {
            return;
        }
        try {
            $response = ($this->http ?? new Client(['timeout' => 5]))->post($url, (string)json_encode([
                'alert' => $alert,
                'message' => $message,
                'application' => Settings::get(SettingsPlugin::APPLICATION_NAME),
                'email' => $user?->get(UserProvisioner::EMAIL),
                'count' => $count,
                'at' => DateTime::now()->toIso8601String(),
            ]), ['type' => 'json']);
            if (!$response->isOk()) {
                Log::warning(sprintf('The alert webhook answered %d.', $response->getStatusCode()));
            }
        } catch (Throwable $e) {
            Log::warning('The alert webhook could not be reached: ' . get_class($e));
        }
    }

    /**
     * Whether the next revocation must be held.
     *
     * @return bool
     */
    public function mustHold(): bool
    {
        if ($this->heldRevocations()->find()->where(['resolved_at IS' => null])->count() > 0) {
            return true;
        }
        $revoked = $this->accessChanges()->revokedSince(DateTime::now()->subMinutes(self::HOLD_WINDOW_MINUTES));
        $users = $this->users();
        $query = $users->find()->where([$users->aliasField(UserProvisioner::IS_ACTIVE) => true]);
        $system = UserProvisioner::systemUserIds();
        if ($system !== []) {
            $query->where([$users->aliasField('id') . ' NOT IN' => $system]);
        }
        $active = $query->count();
        // Everyone who had access at the start of the window, the one about to
        // go still among the active.
        $base = $active + $revoked;

        return $base > 0 && ($revoked + 1) >= self::HOLD_SHARE * $base;
    }

    /**
     * @param \RockSoftware\Users\Model\Entity\HeldRevocation $held The held revocation.
     * @param string $type `REVOCATION_APPROVED` or `REVOCATION_REJECTED`.
     * @param int|null $actorUserId Who decided.
     * @param string|null $detail Why, when not a person's decision.
     * @return void
     */
    private function resolve(HeldRevocation $held, string $type, ?int $actorUserId, ?string $detail = null): void
    {
        $change = $this->accessChanges()->record($held->user_id, $type, [
            'actor_user_id' => $actorUserId,
            'detail' => $detail,
        ]);
        $held->set('resolved_at', DateTime::now(), ['guard' => false]);
        $held->set('resolved_by_user_id', $actorUserId, ['guard' => false]);
        $held->set('access_change_id', $change->id, ['guard' => false]);
        $this->heldRevocations()->saveOrFail($held);
    }

    /**
     * @param string $name The event.
     * @param array<string, mixed> $data Its data.
     * @return void
     */
    private function dispatch(string $name, array $data): void
    {
        EventManager::instance()->dispatch(new Event($name, $this, $data));
    }

    /**
     * @return \Cake\ORM\Table
     */
    private function users(): Table
    {
        return (new UserProvisioner())->users();
    }

    /**
     * @return \RockSoftware\Users\Model\Table\AccessChangesTable
     */
    private function accessChanges(): AccessChangesTable
    {
        /** @var \RockSoftware\Users\Model\Table\AccessChangesTable */
        return $this->fetchTable('RockSoftware/Users.AccessChanges');
    }

    /**
     * @return \RockSoftware\Users\Model\Table\HeldRevocationsTable
     */
    private function heldRevocations(): HeldRevocationsTable
    {
        /** @var \RockSoftware\Users\Model\Table\HeldRevocationsTable */
        return $this->fetchTable('RockSoftware/Users.HeldRevocations');
    }
}
