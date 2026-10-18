<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * A revocation the mass-removal stop held. It waits for a person to approve
 * it (access goes) or reject it (access stays); `resolved_at` is set either
 * way and `access_change_id` points at the history row of the decision.
 *
 * @property int $id
 * @property int $user_id
 * @property int $revocation_type_id
 * @property \Cake\I18n\DateTime|null $resolved_at
 * @property int|null $resolved_by_user_id
 * @property int|null $access_change_id
 * @property \Cake\I18n\DateTime $created
 * @property \RockSoftware\Users\Model\Entity\RevocationType|null $revocation_type
 */
class HeldRevocation extends Entity
{
    /**
     * Written by the plugin only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];
}
