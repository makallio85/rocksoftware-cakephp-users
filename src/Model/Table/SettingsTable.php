<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use RockSoftware\Users\Model\Entity\Setting;
use RockSoftware\Users\Service\SecretCipher;

class SettingsTable extends Table
{
    public const DEFAULT_RETENTION_DAYS = 180;
    public const MAX_SESSION_HOURS = 24 * 90;
    public const MAX_IDLE_MINUTES = 60 * 24;
    public const MAX_RETENTION_DAYS = 3650;

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_settings');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Setting::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->requirePresence('issuer_url', 'create')
            ->notEmptyString('issuer_url')
            ->add('issuer_url', 'https', [
                'rule' => fn($value) => is_string($value) && str_starts_with($value, 'https://'),
                'message' => 'The issuer must be an https URL.',
            ])
            ->requirePresence('client_id', 'create')
            ->notEmptyString('client_id')
            ->requirePresence('client_secret', 'create')
            ->notEmptyString('client_secret')
            ->allowEmptyString('required_group');
    }

    /**
     * The connection, enabled or not, or null when none has been configured.
     *
     * @return \RockSoftware\Users\Model\Entity\Setting|null
     */
    public function current(): ?Setting
    {
        /** @var \RockSoftware\Users\Model\Entity\Setting|null */
        return $this->find()->orderBy(['id' => 'ASC'])->first();
    }

    /**
     * The connection when sign-in through the identity provider is switched on.
     *
     * @return \RockSoftware\Users\Model\Entity\Setting|null
     */
    public function enabled(): ?Setting
    {
        $setting = $this->current();

        return $setting !== null && $setting->is_enabled ? $setting : null;
    }

    /**
     * Whether Rock Software ID may connect the application now: before the
     * first connection, or inside a window opened on the settings page.
     *
     * @return bool
     */
    public function provisioningOpen(): bool
    {
        $setting = $this->current();

        return $setting === null
            || ($setting->provisioning_open_until !== null && $setting->provisioning_open_until->isFuture());
    }

    /**
     * Lets Rock Software ID connect the application again for a while.
     *
     * @param int $minutes How long.
     * @return \RockSoftware\Users\Model\Entity\Setting
     */
    public function openProvisioning(int $minutes): Setting
    {
        $setting = $this->current();
        if ($setting === null) {
            throw new RecordNotFoundException('There is no connection yet; provisioning is open.');
        }
        $setting->provisioning_open_until = DateTime::now()->addMinutes($minutes);

        return $this->saveOrFail($setting);
    }

    /**
     * Whether the application's own password form may sign people in. Always
     * true before the first connection: until Rock Software ID signs people
     * in, the password form is the only way in.
     *
     * @return bool
     */
    public function passwordSignInAllowed(): bool
    {
        $setting = $this->current();

        return $setting === null || $setting->password_sign_in_enabled;
    }

    /**
     * Turns the application's own password sign-in on or off.
     *
     * @param bool $enabled On or off.
     * @param int $userId Who changed it.
     * @return \RockSoftware\Users\Model\Entity\Setting
     */
    public function setPasswordSignIn(bool $enabled, int $userId): Setting
    {
        $setting = $this->current();
        if ($setting === null) {
            throw new RecordNotFoundException('There is no connection yet; password sign-in stays on.');
        }
        $setting->password_sign_in_enabled = $enabled;
        $setting->password_sign_in_changed_by = $userId;
        $setting->password_sign_in_changed_at = DateTime::now();

        return $this->saveOrFail($setting);
    }

    /**
     * The session limits and the sign-in history's retention. Before the first
     * connection, the defaults: no limits, 180 days.
     *
     * @return array{max_hours: int, idle_minutes: int, retention_days: int}
     */
    public function sessionLimits(): array
    {
        $setting = $this->current();

        return [
            'max_hours' => (int)($setting->session_max_hours ?? 0),
            'idle_minutes' => (int)($setting->session_idle_minutes ?? 0),
            'retention_days' => (int)($setting->session_event_retention_days ?? self::DEFAULT_RETENTION_DAYS),
        ];
    }

    /**
     * Changes the session limits.
     *
     * @param int $maxHours Longest session in hours, 0 for none.
     * @param int $idleMinutes Sign-out after this many idle minutes, 0 for never.
     * @param int $retentionDays How long the sign-in history is kept.
     * @return \RockSoftware\Users\Model\Entity\Setting
     */
    public function setSessionLimits(int $maxHours, int $idleMinutes, int $retentionDays): Setting
    {
        $setting = $this->current();
        if ($setting === null) {
            throw new RecordNotFoundException('There is no connection yet.');
        }
        $setting->session_max_hours = max(0, min(self::MAX_SESSION_HOURS, $maxHours));
        $setting->session_idle_minutes = max(0, min(self::MAX_IDLE_MINUTES, $idleMinutes));
        $setting->session_event_retention_days = max(1, min(self::MAX_RETENTION_DAYS, $retentionDays));

        return $this->saveOrFail($setting);
    }

    /**
     * Writes the one connection row, encrypting the client secret.
     *
     * @param array<string, mixed> $data issuer_url, client_id, client_secret (plain), required_group, is_enabled.
     * @return \RockSoftware\Users\Model\Entity\Setting
     */
    public function store(array $data): Setting
    {
        if (isset($data['client_secret']) && is_string($data['client_secret']) && $data['client_secret'] !== '') {
            $data['client_secret'] = SecretCipher::encrypt($data['client_secret']);
        }
        if (isset($data['issuer_url']) && is_string($data['issuer_url'])) {
            $data['issuer_url'] = rtrim($data['issuer_url'], '/') . '/';
        }

        $setting = $this->current() ?? $this->newEmptyEntity();
        $setting = $this->patchEntity($setting, $data);

        return $this->saveOrFail($setting);
    }

    /**
     * The decrypted client secret.
     *
     * @param \RockSoftware\Users\Model\Entity\Setting $setting The connection.
     * @return string
     */
    public function clientSecret(Setting $setting): string
    {
        return SecretCipher::decrypt($setting->client_secret);
    }
}
