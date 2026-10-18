<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property int $scim_group_id
 * @property int $user_id
 * @property \Cake\I18n\DateTime $created
 */
class ScimGroupMember extends Entity
{
    /**
     * Written by the plugin only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];
}
