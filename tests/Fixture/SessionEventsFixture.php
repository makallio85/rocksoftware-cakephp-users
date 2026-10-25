<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no sign-in history. The event and method
 * types are the migration's system rows.
 */
class SessionEventsFixture extends TestFixture
{
    public string $table = 'rock_software_users_session_events';

    public array $records = [];
}
