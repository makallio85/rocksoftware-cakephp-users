<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\I18n\DateTime;
use Cake\ORM\Entity;

/**
 * The link between one of the application's users and their identity provider
 * account.
 *
 * @property int $id
 * @property int $user_id The application's `users.id`.
 * @property string $external_id The provider's subject identifier.
 * @property \Cake\I18n\DateTime|null $synced_at
 * @property \Cake\I18n\DateTime|null $last_signed_in_at
 * @property \Cake\I18n\DateTime|null $sessions_valid_from Sessions established before this have ended.
 * @property string|null $user_name The SCIM `userName`.
 * @property string|null $pending_email An address Rock Software ID sent that another user has.
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class Identity extends Entity
{
    /**
     * How long an email conflict counts without Rock Software ID repeating it.
     * A real conflict comes back with every full sync (every 4 hours); one
     * resolved by changing the address back never does, because Rock Software
     * ID then sees nothing to send.
     */
    public const EMAIL_CONFLICT_HOURS = 5;

    /**
     * The address Rock Software ID sent that another user has, while the
     * conflict is current; null otherwise.
     *
     * @return string|null
     */
    public function currentEmailConflict(): ?string
    {
        $pending = $this->get('pending_email');
        $confirmed = $this->get('synced_at');
        if (!is_string($pending) || !$confirmed instanceof DateTime) {
            return null;
        }

        return $confirmed < DateTime::now()->subHours(self::EMAIL_CONFLICT_HOURS) ? null : $pending;
    }

    /**
     * Written by the plugin only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];
}
