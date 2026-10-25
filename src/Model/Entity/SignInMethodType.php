<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * How a sign-in was attempted. The values equal `SignInSession::METHOD_*`.
 *
 * @property int $id
 * @property string $value
 * @property string $label
 * @property int $sort_order
 */
class SignInMethodType extends Entity
{
    public const OIDC = 'oidc';
    public const PASSWORD = 'password';
    public const EMERGENCY = 'emergency';

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
            self::OIDC => __d('rock_software_users', 'Rock Software ID'),
            self::PASSWORD => __d('rock_software_users', 'Password'),
            self::EMERGENCY => __d('rock_software_users', 'Emergency link'),
            default => $value,
        };
    }
}
