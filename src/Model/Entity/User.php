<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * A person in the application. Rock Software ID owns these fields; the
 * application shows them read-only and keeps its permissions elsewhere.
 *
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property string|null $phone
 * @property bool $is_active
 * @property \Cake\I18n\DateTime|null $access_from
 * @property \Cake\I18n\DateTime|null $access_until
 */
class User extends Entity
{
    /**
     * Written by the plugin only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];

    /**
     * @var array<string>
     */
    protected array $_hidden = ['password'];
}
