<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * One save of the identity provider connection on the settings page.
 *
 * @property int $id
 * @property int $user_id
 * @property \Cake\I18n\DateTime $created
 */
class SettingChange extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'user_id' => true,
    ];
}
