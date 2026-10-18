<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no rows here. Tests create what they need.
 */
class ScimGroupsFixture extends TestFixture
{
    public string $table = 'rock_software_users_scim_groups';

    public array $records = [];
}
