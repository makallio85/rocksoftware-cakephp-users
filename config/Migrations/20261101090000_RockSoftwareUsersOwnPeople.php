<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * 3.0: Rock Software ID owns a person's lifecycle, pushed to the application
 * through SCIM; the application owns only permissions.
 *
 * - **`users` is adopted.** The application's own migration creates the table;
 *   this one adds the plugin's columns when they are missing (`name`, `phone`,
 *   `is_active`, `access_from`, `access_until`) and a unique index on `email`.
 *   It never drops or changes an application's column, and ids stay, so every
 *   foreign key to `users.id` keeps working. It stops before changing anything
 *   when `users` does not exist yet (the application's migrations must run
 *   first) or when two users share an email address.
 * - `rock_software_users_identities` gains `user_name` (the SCIM `userName`,
 *   which Rock Software ID looks a user up by) and `pending_email` (an address
 *   Rock Software ID sent that another user already has).
 * - `rock_software_users_settings` gains `scim_token_hash`.
 * - `rock_software_users_access_changes`: the access history, typed by
 *   `rock_software_users_access_change_types`; a revocation also by
 *   `rock_software_users_revocation_types`.
 * - `rock_software_users_held_revocations`: revocations the mass-removal stop
 *   holds for a person to approve or reject.
 * - `rock_software_users_scim_groups` and `rock_software_users_scim_group_members`:
 *   groups as Rock Software ID pushed them. Being in one is having access.
 */
class RockSoftwareUsersOwnPeople extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->adoptUsers();

        $identities = $this->table('rock_software_users_identities');
        if (!$identities->hasColumn('user_name')) {
            $identities
                ->addColumn('user_name', 'string', ['limit' => 150, 'null' => true, 'default' => null])
                ->addColumn('pending_email', 'string', ['limit' => 190, 'null' => true, 'default' => null])
                ->addIndex(['user_name'], ['name' => 'rsu_identities_user_name'])
                ->update();
        }

        $settings = $this->table('rock_software_users_settings');
        if (!$settings->hasColumn('scim_token_hash')) {
            $settings
                ->addColumn('scim_token_hash', 'string', ['limit' => 64, 'null' => true, 'default' => null])
                ->update();
        }

        $this->typeTable('rock_software_users_access_change_types', [
            ['value' => 'granted', 'label' => 'Access granted', 'sort_order' => 10],
            ['value' => 'revoked', 'label' => 'Access removed', 'sort_order' => 20],
            ['value' => 'details_updated', 'label' => 'Details updated', 'sort_order' => 30],
            ['value' => 'email_conflict', 'label' => 'Email conflict', 'sort_order' => 40],
            ['value' => 'revocation_held', 'label' => 'Removal held', 'sort_order' => 50],
            ['value' => 'revocation_approved', 'label' => 'Held removal approved', 'sort_order' => 60],
            ['value' => 'revocation_rejected', 'label' => 'Held removal rejected', 'sort_order' => 70],
            ['value' => 'access_restored', 'label' => 'Access restored on the server', 'sort_order' => 80],
            ['value' => 'emergency_link_issued', 'label' => 'Emergency sign-in link issued', 'sort_order' => 90],
        ]);
        $this->typeTable('rock_software_users_revocation_types', [
            ['value' => 'deactivated', 'label' => 'Deactivated in Rock Software ID', 'sort_order' => 10],
            ['value' => 'left_groups', 'label' => "Left the application's groups", 'sort_order' => 20],
            ['value' => 'removed', 'label' => 'Removed from the application in Rock Software ID', 'sort_order' => 30],
        ]);
        $this->table('rock_software_users_session_event_types')->insert([
            ['value' => 'access_not_started', 'label' => 'Access has not started', 'sort_order' => 150],
            ['value' => 'access_ended', 'label' => 'Access has ended', 'sort_order' => 160],
        ])->save();

        $this->table('rock_software_users_access_changes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('access_change_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('revocation_type_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('actor_user_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('detail', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'created'], ['name' => 'rsu_access_changes_user_created'])
            ->addIndex(['access_change_type_id', 'created'], ['name' => 'rsu_access_changes_type_created'])
            ->addForeignKey('access_change_type_id', 'rock_software_users_access_change_types', 'id', [
                'constraint' => 'rsu_access_changes_access_change_type',
            ])
            ->addForeignKey('revocation_type_id', 'rock_software_users_revocation_types', 'id', [
                'constraint' => 'rsu_access_changes_revocation_type',
            ])
            ->create();

        $this->table('rock_software_users_held_revocations', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('revocation_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('resolved_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('resolved_by_user_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('access_change_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['resolved_at', 'created'], ['name' => 'rsu_held_revocations_resolved_created'])
            ->addIndex(['user_id'], ['name' => 'rsu_held_revocations_user'])
            ->addForeignKey('revocation_type_id', 'rock_software_users_revocation_types', 'id', [
                'constraint' => 'rsu_held_revocations_revocation_type',
            ])
            ->create();

        $this->table('rock_software_users_scim_groups', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('external_id', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('display_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['display_name'], ['name' => 'rsu_scim_groups_display_name'])
            ->create();

        $this->table('rock_software_users_scim_group_members', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('scim_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['scim_group_id', 'user_id'], ['unique' => true, 'name' => 'rsu_scim_group_members_group_user'])
            ->addIndex(['user_id'], ['name' => 'rsu_scim_group_members_user'])
            ->addForeignKey('scim_group_id', 'rock_software_users_scim_groups', 'id', [
                'constraint' => 'rsu_scim_group_members_scim_group',
                'delete' => 'CASCADE',
            ])
            ->create();
    }

    /**
     * The tables go; the columns added to `users` stay, because the
     * application may already have written to them.
     *
     * @return void
     */
    public function down(): void
    {
        foreach (
            [
                'rock_software_users_scim_group_members',
                'rock_software_users_scim_groups',
                'rock_software_users_held_revocations',
                'rock_software_users_access_changes',
                'rock_software_users_revocation_types',
                'rock_software_users_access_change_types',
            ] as $table
        ) {
            $this->table($table)->drop()->save();
        }
        $this->execute(
            "DELETE FROM rock_software_users_session_event_types WHERE value IN ('access_not_started', 'access_ended')",
        );
        $this->table('rock_software_users_settings')->removeColumn('scim_token_hash')->update();
        $this->table('rock_software_users_identities')
            ->removeIndexByName('rsu_identities_user_name')
            ->removeColumn('user_name')
            ->removeColumn('pending_email')
            ->update();
    }

    /**
     * @return void
     */
    private function adoptUsers(): void
    {
        if (!$this->hasTable('users')) {
            throw new RuntimeException(
                'The users table does not exist yet. The application\'s own migrations create it and must run '
                . 'before the RockSoftware/Users migrations (bin/cake migrations migrate, then '
                . 'bin/cake migrations migrate -p RockSoftware/Users).',
            );
        }

        $duplicates = $this->fetchAll(
            'SELECT LOWER(email) AS address, COUNT(*) AS n FROM users GROUP BY LOWER(email) HAVING COUNT(*) > 1',
        );
        if ($duplicates !== []) {
            throw new RuntimeException(sprintf(
                '%d email addresses are used by more than one user. Each person needs their own address before '
                . 'the users table can be adopted: SELECT email FROM users GROUP BY LOWER(email) HAVING COUNT(*) > 1',
                count($duplicates),
            ));
        }

        $columns = [
            'name' => ['string', ['limit' => 255, 'null' => true, 'default' => null]],
            'phone' => ['string', ['limit' => 32, 'null' => true, 'default' => null]],
            'is_active' => ['boolean', ['null' => false, 'default' => true]],
            'access_from' => ['datetime', ['null' => true, 'default' => null]],
            'access_until' => ['datetime', ['null' => true, 'default' => null]],
        ];
        foreach ($columns as $column => [$type, $options]) {
            $users = $this->table('users');
            if (!$users->hasColumn($column)) {
                $users->addColumn($column, $type, $options)->update();
            }
        }

        if (!$this->hasUniqueEmailIndex()) {
            $this->table('users')->addIndex(['email'], ['unique' => true, 'name' => 'rsu_users_email'])->update();
        }
    }

    /**
     * @return bool
     */
    private function hasUniqueEmailIndex(): bool
    {
        $schema = $this->getAdapter()->getConnection()->getSchemaCollection()->describe('users');
        foreach ($schema->constraints() as $name) {
            $constraint = (array)$schema->getConstraint($name);
            if (($constraint['type'] ?? null) === 'unique' && ($constraint['columns'] ?? null) === ['email']) {
                return true;
            }
        }
        foreach ($schema->indexes() as $name) {
            $index = (array)$schema->getIndex($name);
            if (($index['type'] ?? null) === 'unique' && ($index['columns'] ?? null) === ['email']) {
                return true;
            }
        }

        return false;
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
