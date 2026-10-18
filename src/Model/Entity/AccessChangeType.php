<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * What an access history row records. The rows are system rows; the plugin
 * binds to them by value.
 *
 * @property int $id
 * @property string $value
 * @property string $label
 * @property int $sort_order
 */
class AccessChangeType extends Entity
{
    public const GRANTED = 'granted';
    public const REVOKED = 'revoked';
    public const DETAILS_UPDATED = 'details_updated';
    public const EMAIL_CONFLICT = 'email_conflict';
    public const REVOCATION_HELD = 'revocation_held';
    public const REVOCATION_APPROVED = 'revocation_approved';
    public const REVOCATION_REJECTED = 'revocation_rejected';
    public const ACCESS_RESTORED = 'access_restored';
    public const EMERGENCY_LINK_ISSUED = 'emergency_link_issued';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'value' => true,
        'label' => true,
        'sort_order' => true,
    ];

    /**
     * The label in the application's language.
     *
     * @param string $value One of the value constants.
     * @return string
     */
    public static function label(string $value): string
    {
        return match ($value) {
            self::GRANTED => __d('rock_software_users', 'Access granted'),
            self::REVOKED => __d('rock_software_users', 'Access removed'),
            self::DETAILS_UPDATED => __d('rock_software_users', 'Details updated'),
            self::EMAIL_CONFLICT => __d('rock_software_users', 'Email conflict'),
            self::REVOCATION_HELD => __d('rock_software_users', 'Removing access held'),
            self::REVOCATION_APPROVED => __d('rock_software_users', 'Held removal approved'),
            self::REVOCATION_REJECTED => __d('rock_software_users', 'Held removal rejected: access kept'),
            self::ACCESS_RESTORED => __d('rock_software_users', 'Access restored on the server'),
            self::EMERGENCY_LINK_ISSUED => __d('rock_software_users', 'Emergency sign-in link issued'),
            default => $value,
        };
    }
}
