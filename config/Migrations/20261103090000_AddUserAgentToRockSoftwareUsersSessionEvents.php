<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The browser of each sign-in history row (its User-Agent, cut to 255
 * characters), shown beside the client's address.
 */
class AddUserAgentToRockSoftwareUsersSessionEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('rock_software_users_session_events')
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'ip_address'])
            ->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('rock_software_users_session_events')->removeColumn('user_agent')->update();
    }
}
