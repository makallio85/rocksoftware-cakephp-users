<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\Identity;

/**
 * @method \RockSoftware\Users\Model\Entity\Identity newEmptyEntity()
 * @method \RockSoftware\Users\Model\Entity\Identity saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class IdentitiesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_identities');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Identity::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * @param string $externalId The provider's subject identifier.
     * @return \RockSoftware\Users\Model\Entity\Identity|null
     */
    public function findByExternalId(string $externalId): ?Identity
    {
        /** @var \RockSoftware\Users\Model\Entity\Identity|null */
        return $this->find()->where(['external_id' => $externalId])->first();
    }

    /**
     * @param int $userId The application's user id.
     * @return \RockSoftware\Users\Model\Entity\Identity|null
     */
    public function findForUser(int $userId): ?Identity
    {
        /** @var \RockSoftware\Users\Model\Entity\Identity|null */
        return $this->find()->where(['user_id' => $userId])->first();
    }
}
