<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\ScimGroupMember;

class ScimGroupMembersTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_scim_group_members');
        $this->setPrimaryKey('id');
        $this->setEntityClass(ScimGroupMember::class);
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
    }

    /**
     * Whether the user is in any pushed group.
     *
     * @param int $userId The user.
     * @return bool
     */
    public function isMember(int $userId): bool
    {
        return $this->exists(['user_id' => $userId]);
    }
}
