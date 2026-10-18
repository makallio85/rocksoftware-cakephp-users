<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\ScimGroup;

/**
 * @property \RockSoftware\Users\Model\Table\ScimGroupMembersTable $ScimGroupMembers
 */
class ScimGroupsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_scim_groups');
        $this->setPrimaryKey('id');
        $this->setDisplayField('display_name');
        $this->setEntityClass(ScimGroup::class);
        $this->addBehavior('Timestamp');
        $this->hasMany('ScimGroupMembers', [
            'className' => 'RockSoftware/Users.ScimGroupMembers',
            'foreignKey' => 'scim_group_id',
            'dependent' => true,
            'cascadeCallbacks' => false,
        ]);
    }
}
