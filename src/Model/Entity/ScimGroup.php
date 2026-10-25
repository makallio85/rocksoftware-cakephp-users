<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * A group as Rock Software ID pushed it. It grants nothing by itself: being a
 * member of any pushed group is having access to the application.
 *
 * @property int $id
 * @property string|null $external_id
 * @property string $display_name
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class ScimGroup extends Entity
{
    /**
     * Written by the plugin only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];
}
