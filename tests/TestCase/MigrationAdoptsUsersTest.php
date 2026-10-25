<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use Throwable;

/**
 * The 3.0 migration adopts the application's existing `users` table: it adds
 * the plugin's columns and a unique email index, keeps every column and row
 * of the application's, and stops before changing anything when the table is
 * missing or two users share an address. Each case runs on a database of its
 * own.
 */
class MigrationAdoptsUsersTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = TMP . 'adopt-' . bin2hex(random_bytes(4)) . '.sqlite';
        ConnectionManager::setConfig('adopt', ['url' => 'sqlite:///' . $this->file]);
    }

    protected function tearDown(): void
    {
        ConnectionManager::get('adopt')->getDriver()->disconnect();
        ConnectionManager::drop('adopt');
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testAddsThePluginsColumnsAndKeepsTheApplications(): void
    {
        $db = $this->db();
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(190) NOT NULL, full_name VARCHAR(100), role_id INTEGER)');
        $db->execute("INSERT INTO users (id, email, full_name, role_id) VALUES (7, 'a@example.test', 'A', 3)");

        $this->migrate();

        $schema = $db->getSchemaCollection()->describe('users');
        foreach (['name', 'phone', 'is_active', 'access_from', 'access_until', 'full_name', 'role_id'] as $column) {
            $this->assertTrue($schema->hasColumn($column), $column);
        }
        $row = $db->execute('SELECT id, role_id, is_active FROM users')->fetch('assoc');
        $this->assertSame(['id' => 7, 'role_id' => 3, 'is_active' => 1], array_map('intval', $row));
        $this->expectException(Throwable::class);
        $db->execute("INSERT INTO users (id, email) VALUES (8, 'a@example.test')");
    }

    public function testStopsWhenTwoUsersShareAnAddress(): void
    {
        $db = $this->db();
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(190) NOT NULL)');
        $db->execute("INSERT INTO users (id, email) VALUES (1, 'same@example.test'), (2, 'SAME@example.test')");

        try {
            $this->migrate();
            $this->fail('The migration must stop.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('more than one user', $this->messages($e));
        }
        $this->assertFalse($db->getSchemaCollection()->describe('users')->hasColumn('phone'), 'nothing changed');
    }

    public function testStopsWhenTheApplicationHasNotCreatedUsers(): void
    {
        try {
            $this->migrate();
            $this->fail('The migration must stop.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('must run before the RockSoftware/Users migrations', $this->messages($e));
        }
    }

    private function db(): Connection
    {
        /** @var \Cake\Database\Connection */
        return ConnectionManager::get('adopt');
    }

    private function migrate(): void
    {
        (new Migrations(['connection' => 'adopt', 'plugin' => 'RockSoftware/Users']))->migrate();
    }

    private function messages(Throwable $e): string
    {
        $messages = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return implode(' | ', $messages);
    }
}
