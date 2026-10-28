<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Access tokens (bearer tokens) for the application's API and MCP paths: the
 * audience a token must name, and the issuer of the client that requests
 * them when it is another provider of Rock Software ID than the sign-in
 * connection. No audience means no token is accepted.
 */
class AddBearerToRockSoftwareUsersSettings extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('rock_software_users_settings')
            ->addColumn('bearer_audience', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('bearer_issuer_url', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('rock_software_users_settings')
            ->removeColumn('bearer_audience')
            ->removeColumn('bearer_issuer_url')
            ->update();
    }
}
