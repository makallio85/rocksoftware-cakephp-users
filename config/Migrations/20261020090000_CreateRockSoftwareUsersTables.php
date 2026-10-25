<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The plugin's own tables. No foreign key to the application's `users`: that
 * table belongs to the application, and nothing of the application's has to
 * exist first. Ids are unsigned, so an application's unsigned or signed user
 * ids both fit.
 *
 * - `rock_software_users_settings`: the one connection to Rock Software ID
 *   (client secret encrypted), password sign-in, session limits.
 * - `rock_software_users_setting_changes`: who changed the settings, when.
 * - `rock_software_users_identities`: the link between a user and their Rock
 *   Software ID account (`external_id`).
 * - `rock_software_users_provisionings`: connections Rock Software ID pushed;
 *   `jti` is unique, so a provisioning token is accepted once.
 * - `rock_software_users_emergency_sign_ins`: single-use emergency links,
 *   stored as hashes.
 * - `rock_software_users_session_events`: the sign-in history, with its type
 *   tables `rock_software_users_session_event_types` and
 *   `rock_software_users_sign_in_method_types` (system rows the plugin binds
 *   to by value).
 */
class CreateRockSoftwareUsersTables extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('rock_software_users_settings', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('issuer_url', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('client_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('client_secret', 'text', ['null' => false])
            ->addColumn('required_group', 'string', ['limit' => 150, 'null' => true, 'default' => null])
            ->addColumn('is_enabled', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('provisioning_open_until', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('password_sign_in_enabled', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('password_sign_in_changed_by', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('password_sign_in_changed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('session_max_hours', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('session_idle_minutes', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('session_event_retention_days', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 180,
            ])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->create();

        $this->table('rock_software_users_setting_changes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['created'], ['name' => 'rsu_setting_changes_created'])
            ->create();

        $this->table('rock_software_users_identities', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('external_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('synced_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('last_signed_in_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('sessions_valid_from', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['external_id'], ['unique' => true, 'name' => 'rsu_identities_external_id'])
            ->addIndex(['user_id'], ['unique' => true, 'name' => 'rsu_identities_user_id'])
            ->create();

        $this->table('rock_software_users_provisionings', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('jti', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('issuer_url', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('client_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('remote_ip', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['jti'], ['unique' => true, 'name' => 'rsu_provisionings_jti'])
            ->addIndex(['created'], ['name' => 'rsu_provisionings_created'])
            ->create();

        $this->table('rock_software_users_emergency_sign_ins', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'rsu_emergency_sign_ins_token_hash'])
            ->addIndex(['user_id'], ['name' => 'rsu_emergency_sign_ins_user_id'])
            ->create();

        $this->typeTable('rock_software_users_session_event_types', [
            ['value' => 'signed_in', 'label' => 'Signed in', 'sort_order' => 10],
            ['value' => 'wrong_password', 'label' => 'Wrong password', 'sort_order' => 20],
            ['value' => 'unknown_email', 'label' => 'Unknown email address', 'sort_order' => 30],
            ['value' => 'user_inactive', 'label' => 'User deactivated', 'sort_order' => 40],
            ['value' => 'not_in_group', 'label' => 'Not in the required group', 'sort_order' => 50],
            ['value' => 'throttled', 'label' => 'Too many attempts', 'sort_order' => 60],
            ['value' => 'password_sign_in_off', 'label' => 'Password sign-in is off', 'sort_order' => 70],
            ['value' => 'sign_in_failed', 'label' => 'Sign-in failed', 'sort_order' => 80],
            ['value' => 'signed_out', 'label' => 'Signed out', 'sort_order' => 90],
            ['value' => 'signed_out_by_provider', 'label' => 'Signed out by Rock Software ID', 'sort_order' => 100],
            ['value' => 'session_expired', 'label' => 'Session expired', 'sort_order' => 110],
            ['value' => 'sessions_ended', 'label' => 'Sessions ended by an administrator', 'sort_order' => 120],
            ['value' => 'user_deactivated', 'label' => 'Deactivated by an administrator', 'sort_order' => 130],
            ['value' => 'user_activated', 'label' => 'Activated by an administrator', 'sort_order' => 140],
        ]);
        $this->typeTable('rock_software_users_sign_in_method_types', [
            ['value' => 'oidc', 'label' => 'Rock Software ID', 'sort_order' => 10],
            ['value' => 'password', 'label' => 'Password', 'sort_order' => 20],
            ['value' => 'emergency', 'label' => 'Emergency link', 'sort_order' => 30],
        ]);

        $this->table('rock_software_users_session_events', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => true, 'default' => null])
            ->addColumn('session_event_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('sign_in_method_type_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('actor_user_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'created'], ['name' => 'rsu_session_events_user_created'])
            ->addIndex(['email', 'created'], ['name' => 'rsu_session_events_email_created'])
            ->addIndex(['ip_address', 'created'], ['name' => 'rsu_session_events_ip_created'])
            ->addIndex(['created'], ['name' => 'rsu_session_events_created'])
            ->addForeignKey('session_event_type_id', 'rock_software_users_session_event_types', 'id', [
                'constraint' => 'rsu_session_events_session_event_type',
            ])
            ->addForeignKey('sign_in_method_type_id', 'rock_software_users_sign_in_method_types', 'id', [
                'constraint' => 'rsu_session_events_sign_in_method_type',
            ])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $tables = [
            'rock_software_users_session_events',
            'rock_software_users_sign_in_method_types',
            'rock_software_users_session_event_types',
            'rock_software_users_emergency_sign_ins',
            'rock_software_users_provisionings',
            'rock_software_users_identities',
            'rock_software_users_setting_changes',
            'rock_software_users_settings',
        ];
        foreach ($tables as $table) {
            $this->table($table)->drop()->save();
        }
    }

    /**
     * @param string $name The table.
     * @param array<array<string, mixed>> $rows Its system rows.
     * @return void
     */
    private function typeTable(string $name, array $rows): void
    {
        $this->table($name, ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('value', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['value'], ['unique' => true, 'name' => $name . '_value'])
            ->create();
        $this->table($name)->insert($rows)->save();
    }
}
