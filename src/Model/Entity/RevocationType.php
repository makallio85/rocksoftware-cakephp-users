<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * Why Rock Software ID took a person's access away. The rows are system rows;
 * the plugin binds to them by value.
 *
 * @property int $id
 * @property string $value
 * @property string $label
 * @property int $sort_order
 */
class RevocationType extends Entity
{
    /** `active: false`: the account was deactivated. */
    public const DEACTIVATED = 'deactivated';
    /** The person is in none of the application's groups any more. */
    public const LEFT_GROUPS = 'left_groups';
    /** `DELETE /Users/{id}`: Rock Software ID stopped sending the person here. */
    public const REMOVED = 'removed';

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
            self::DEACTIVATED => __d('rock_software_users', 'Deactivated in Rock Software ID'),
            self::LEFT_GROUPS => __d('rock_software_users', "Left the application's groups"),
            self::REMOVED => __d('rock_software_users', 'Removed from the application in Rock Software ID'),
            default => $value,
        };
    }
}
