<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Entity;

use Cake\ORM\Entity;

/**
 * One connection pushed by Rock Software ID through `POST /auth/provision`.
 *
 * @property int $id
 * @property string $jti
 * @property string $issuer_url
 * @property string $client_id
 * @property string|null $remote_ip
 * @property \Cake\I18n\DateTime $created
 */
class Provisioning extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'jti' => true,
        'issuer_url' => true,
        'client_id' => true,
        'remote_ip' => true,
    ];
}
