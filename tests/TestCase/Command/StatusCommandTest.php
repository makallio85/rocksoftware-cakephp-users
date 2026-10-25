<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;

class StatusCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = ['plugin.RockSoftware/Users.Settings'];

    public function testNotConfigured(): void
    {
        $this->exec('rock_software_users status');

        $this->assertExitSuccess();
        $this->assertOutputContains('{"configured":false,"enabled":false,"issuer":null,"client_id":null,"required_group":null}');
    }

    public function testConfiguredWithoutTheSecret(): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store([
            'issuer_url' => 'https://id.example.test/application/o/app/',
            'client_id' => 'app',
            'client_secret' => 'the-secret',
            'required_group' => 'app-staff',
            'is_enabled' => true,
        ]);

        $this->exec('rock_software_users status');

        $this->assertExitSuccess();
        $this->assertOutputContains('"issuer":"https://id.example.test/application/o/app/","client_id":"app","required_group":"app-staff"');
        $this->assertOutputNotContains('the-secret');
    }
}
