<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\SettingChange;

/**
 * @method \RockSoftware\Users\Model\Entity\SettingChange newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 * @method \RockSoftware\Users\Model\Entity\SettingChange saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class SettingChangesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_setting_changes');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SettingChange::class);
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);
    }

    /**
     * The most recent change.
     *
     * @return \RockSoftware\Users\Model\Entity\SettingChange|null
     */
    public function latest(): ?SettingChange
    {
        /** @var \RockSoftware\Users\Model\Entity\SettingChange|null */
        return $this->find()
            ->orderBy([$this->aliasField('created') => 'DESC', $this->aliasField('id') => 'DESC'])
            ->first();
    }
}
