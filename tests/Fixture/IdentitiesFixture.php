<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no identities.
 */
class IdentitiesFixture extends TestFixture
{
    public string $table = 'rock_software_users_identities';

    public array $records = [];
}
