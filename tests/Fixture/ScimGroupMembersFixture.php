<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no rows here. Tests create what they need.
 */
class ScimGroupMembersFixture extends TestFixture
{
    public string $table = 'rock_software_users_scim_group_members';

    public array $records = [];
}
