<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * One of a person's other addresses, as Rock Software ID sent it. The address
 * this application uses is `users.email`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string|null $label Where the address belongs, e.g. "Rock Software".
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class UserEmail extends Entity
{
    /**
     * Written by the plugin only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];
}
