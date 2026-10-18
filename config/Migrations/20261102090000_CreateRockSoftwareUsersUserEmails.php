<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * `rock_software_users_user_emails`: a person's other addresses as Rock
 * Software ID sends them (another company's address, the account address),
 * beside the one this application uses, which stays in `users.email`. They are
 * shown on the users page and searched, so one person with two addresses is
 * recognised as one.
 */
class CreateRockSoftwareUsersUserEmails extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('rock_software_users_user_emails', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'email'], ['unique' => true, 'name' => 'rsu_user_emails_user_email'])
            ->addIndex(['email'], ['name' => 'rsu_user_emails_email'])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('rock_software_users_user_emails')->drop()->save();
    }
}
