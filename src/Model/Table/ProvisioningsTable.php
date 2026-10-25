<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\Provisioning;

/**
 * @method \RockSoftware\Users\Model\Entity\Provisioning newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 * @method \RockSoftware\Users\Model\Entity\Provisioning saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class ProvisioningsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_provisionings');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Provisioning::class);
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);
    }

    /**
     * Whether a token id has been accepted before.
     *
     * @param string $jti The token id.
     * @return bool
     */
    public function used(string $jti): bool
    {
        return $this->exists(['jti' => $jti]);
    }

    /**
     * The most recent provisioning.
     *
     * @return \RockSoftware\Users\Model\Entity\Provisioning|null
     */
    public function latest(): ?Provisioning
    {
        /** @var \RockSoftware\Users\Model\Entity\Provisioning|null */
        return $this->find()
            ->orderBy([$this->aliasField('created') => 'DESC', $this->aliasField('id') => 'DESC'])
            ->first();
    }
}
