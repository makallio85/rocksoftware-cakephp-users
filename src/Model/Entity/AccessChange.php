<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * One row of a user's access history.
 *
 * @property int $id
 * @property int $user_id
 * @property int $access_change_type_id
 * @property int|null $revocation_type_id
 * @property int|null $actor_user_id Null when Rock Software ID or the system made the change.
 * @property string|null $detail
 * @property \Cake\I18n\DateTime $created
 * @property \RockSoftware\Users\Model\Entity\AccessChangeType|null $access_change_type
 * @property \RockSoftware\Users\Model\Entity\RevocationType|null $revocation_type
 */
class AccessChange extends Entity
{
    /**
     * Written by the plugin only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];
}
