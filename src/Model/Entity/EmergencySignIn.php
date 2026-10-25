<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * A single-use sign-in link issued from the command line.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property \Cake\I18n\DateTime $expires_at
 * @property \Cake\I18n\DateTime|null $used_at
 * @property \Cake\I18n\DateTime $created
 */
class EmergencySignIn extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'user_id' => true,
        'token_hash' => true,
        'expires_at' => true,
        'used_at' => true,
    ];

    /**
     * @var array<string>
     */
    protected array $_hidden = ['token_hash'];
}
