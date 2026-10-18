<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\HeldRevocation;

/**
 * @property \RockSoftware\Users\Model\Table\RevocationTypesTable $RevocationTypes
 */
class HeldRevocationsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_held_revocations');
        $this->setPrimaryKey('id');
        $this->setEntityClass(HeldRevocation::class);
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
        $this->belongsTo('RevocationTypes', [
            'className' => 'RockSoftware/Users.RevocationTypes',
            'foreignKey' => 'revocation_type_id',
        ]);
    }

    /**
     * Revocations waiting for a decision, oldest first.
     *
     * @return array<\RockSoftware\Users\Model\Entity\HeldRevocation>
     */
    public function pending(): array
    {
        return $this->find()
            ->contain(['RevocationTypes'])
            ->where([$this->aliasField('resolved_at') . ' IS' => null])
            ->orderBy([$this->aliasField('created') => 'ASC', $this->aliasField('id') => 'ASC'])
            ->all()
            ->toList();
    }

    /**
     * The revocation waiting for a user, if any.
     *
     * @param int $userId The user.
     * @return \RockSoftware\Users\Model\Entity\HeldRevocation|null
     */
    public function pendingFor(int $userId): ?HeldRevocation
    {
        /** @var \RockSoftware\Users\Model\Entity\HeldRevocation|null */
        return $this->find()
            ->contain(['RevocationTypes'])
            ->where([$this->aliasField('user_id') => $userId, $this->aliasField('resolved_at') . ' IS' => null])
            ->first();
    }
}
