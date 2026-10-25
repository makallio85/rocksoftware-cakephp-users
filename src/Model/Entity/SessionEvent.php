<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * One row of the sign-in history.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $email
 * @property int $session_event_type_id
 * @property int|null $sign_in_method_type_id
 * @property int|null $actor_user_id
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property \Cake\I18n\DateTime $created
 * @property \RockSoftware\Users\Model\Entity\SessionEventType|null $session_event_type
 * @property \RockSoftware\Users\Model\Entity\SignInMethodType|null $sign_in_method_type
 */
class SessionEvent extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'user_id' => true,
        'email' => true,
        'session_event_type_id' => true,
        'sign_in_method_type_id' => true,
        'actor_user_id' => true,
        'ip_address' => true,
    ];
}
