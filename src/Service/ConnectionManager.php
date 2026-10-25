<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Http\Client;
use Cake\ORM\Locator\LocatorAwareTrait;
use RockSoftware\Users\Model\Entity\Setting;
use RockSoftware\Users\Model\Table\SettingsTable;
use Throwable;

/**
 * Reads, tests and changes the connection to the identity provider that every
 * sign-in uses (`rock_software_users_settings`).
 *
 * A change is stored only after the new server has answered: a wrong address
 * saved would lock everyone out, and the only way back would be an emergency
 * link issued from a shell on the server.
 */
class ConnectionManager
{
    use LocatorAwareTrait;

    /**
     * @param \Cake\Http\Client|null $http Injected by tests.
     */
    public function __construct(private ?Client $http = null)
    {
    }

    /**
     * The stored connection, or null before the first configuration.
     *
     * @return \RockSoftware\Users\Model\Entity\Setting|null
     */
    public function current(): ?Setting
    {
        return $this->settings()->current();
    }

    /**
     * Asks a server for its discovery document.
     *
     * @param array<string, mixed> $data issuer_url, client_id, client_secret (blank keeps the stored one).
     * @return string|null Why the server cannot be used, or null when it answered.
     */
    public function check(array $data): ?string
    {
        $issuer = $this->normaliseIssuer((string)($data['issuer_url'] ?? ''));
        if (!str_starts_with($issuer, 'https://')) {
            return __d('rock_software_users', 'The identity server address must be an https URL.');
        }

        // Unsaved: the connection to test, built from the form.
        $setting = new Setting();
        $setting->set('issuer_url', $issuer, ['guard' => false]);
        $setting->set('client_id', (string)($data['client_id'] ?? ''), ['guard' => false]);

        try {
            (new OidcClient($setting, $this->secretFor($data), $this->http))->discovery();
        } catch (SignInException $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            return __d('rock_software_users', 'The identity server could not be asked: {0}', $e->getMessage());
        }

        return null;
    }

    /**
     * Tests the new values, then stores them and records who changed them.
     *
     * @param array<string, mixed> $data issuer_url, client_id, client_secret, required_group.
     * @param int $userId The person saving.
     * @return string|null Why nothing was saved, or null when it was.
     */
    public function update(array $data, int $userId): ?string
    {
        $secret = trim((string)($data['client_secret'] ?? ''));
        if ($this->current() === null && $secret === '') {
            return __d('rock_software_users', 'Enter the client secret: none is stored yet.');
        }
        $clientId = trim((string)($data['client_id'] ?? ''));
        if ($clientId === '') {
            return __d('rock_software_users', 'Enter the client ID.');
        }

        $problem = $this->check($data);
        if ($problem !== null) {
            return $problem;
        }

        $group = trim((string)($data['required_group'] ?? ''));
        $values = [
            'issuer_url' => $this->normaliseIssuer((string)$data['issuer_url']),
            'client_id' => $clientId,
            'required_group' => $group === '' ? null : $group,
            // The identity provider is the only way in; a saved connection is on.
            'is_enabled' => true,
        ];
        if ($secret !== '') {
            $values['client_secret'] = $secret;
        }

        $this->settings()->getConnection()->transactional(function () use ($values, $userId): void {
            $this->settings()->store($values);
            /** @var \RockSoftware\Users\Model\Table\SettingChangesTable $changes */
            $changes = $this->fetchTable('RockSoftware/Users.SettingChanges');
            $changes->saveOrFail($changes->newEntity(['user_id' => $userId]));
        });

        return null;
    }

    /**
     * @param array<string, mixed> $data Submitted values.
     * @return string The secret to test with: the submitted one, else the stored one.
     */
    private function secretFor(array $data): string
    {
        $secret = trim((string)($data['client_secret'] ?? ''));
        if ($secret !== '') {
            return $secret;
        }
        $current = $this->current();

        return $current === null ? '' : $this->settings()->clientSecret($current);
    }

    /**
     * The plugin stores the issuer with exactly one trailing slash.
     *
     * @param string $issuer The issuer as typed.
     * @return string
     */
    private function normaliseIssuer(string $issuer): string
    {
        $issuer = trim($issuer);

        return $issuer === '' ? '' : rtrim($issuer, '/') . '/';
    }

    /**
     * @return \RockSoftware\Users\Model\Table\SettingsTable
     */
    private function settings(): SettingsTable
    {
        /** @var \RockSoftware\Users\Model\Table\SettingsTable */
        return $this->fetchTable('RockSoftware/Users.Settings');
    }
}
