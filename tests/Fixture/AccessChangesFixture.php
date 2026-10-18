<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no rows here. Tests create what they need.
 */
class AccessChangesFixture extends TestFixture
{
    public string $table = 'rock_software_users_access_changes';

    public array $records = [];
}
