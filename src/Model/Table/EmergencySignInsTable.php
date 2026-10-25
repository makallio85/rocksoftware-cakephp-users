<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\EmergencySignIn;

class EmergencySignInsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_emergency_sign_ins');
        $this->setPrimaryKey('id');
        $this->setEntityClass(EmergencySignIn::class);
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);
    }

    /**
     * Issues a link for the user and returns the raw token, which is shown once
     * and never stored.
     *
     * @param int $userId The user the link signs in.
     * @param int $minutes How long the link stays valid.
     * @return string
     */
    public function issue(int $userId, int $minutes): string
    {
        $token = bin2hex(random_bytes(32));
        $this->saveOrFail($this->newEntity([
            'user_id' => $userId,
            'token_hash' => self::hash($token),
            'expires_at' => DateTime::now()->addMinutes($minutes),
        ]));

        return $token;
    }

    /**
     * The unused, unexpired link for a token, or null.
     *
     * @param string $token The raw token from the URL.
     * @return \RockSoftware\Users\Model\Entity\EmergencySignIn|null
     */
    public function findUsable(string $token): ?EmergencySignIn
    {
        /** @var \RockSoftware\Users\Model\Entity\EmergencySignIn|null */
        return $this->find()
            ->where([
                'token_hash' => self::hash($token),
                'used_at IS' => null,
                'expires_at >' => DateTime::now(),
            ])
            ->first();
    }

    /**
     * Marks a link used. The update is conditional, so of two concurrent
     * requests carrying the same token only one succeeds.
     *
     * @param \RockSoftware\Users\Model\Entity\EmergencySignIn $link The link.
     * @return bool Whether this call consumed it.
     */
    public function consume(EmergencySignIn $link): bool
    {
        return $this->updateAll(
            ['used_at' => DateTime::now()],
            ['id' => $link->id, 'used_at IS' => null],
        ) === 1;
    }

    /**
     * @param string $token The raw token.
     * @return string
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
