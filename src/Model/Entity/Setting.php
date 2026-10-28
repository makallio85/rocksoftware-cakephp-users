<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * The connection to the identity provider. There is at most one row.
 *
 * `client_secret` holds the encrypted value; read it through
 * `SettingsTable::clientSecret()`.
 *
 * @property int $id
 * @property string $issuer_url
 * @property string $client_id
 * @property string $client_secret
 * @property string|null $required_group
 * @property bool $is_enabled
 * @property \Cake\I18n\DateTime|null $provisioning_open_until
 * @property bool $password_sign_in_enabled
 * @property int|null $password_sign_in_changed_by
 * @property \Cake\I18n\DateTime|null $password_sign_in_changed_at
 * @property int $session_max_hours
 * @property int $session_idle_minutes
 * @property int $session_event_retention_days
 * @property string|null $scim_token_hash SHA-256 of the SCIM token Rock Software ID pushes with.
 * @property string|null $bearer_audience The audience an access token must name; null accepts none.
 * @property string|null $bearer_issuer_url The access tokens' issuer when it is not `issuer_url`.
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class Setting extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'issuer_url' => true,
        'client_id' => true,
        'client_secret' => true,
        'required_group' => true,
        'is_enabled' => true,
        'provisioning_open_until' => true,
        'password_sign_in_enabled' => true,
        'password_sign_in_changed_by' => true,
        'password_sign_in_changed_at' => true,
        'session_max_hours' => true,
        'session_idle_minutes' => true,
        'session_event_retention_days' => true,
        'scim_token_hash' => true,
        'bearer_audience' => true,
        'bearer_issuer_url' => true,
    ];

    /**
     * @var array<string>
     */
    protected array $_hidden = ['client_secret', 'scim_token_hash'];
}
