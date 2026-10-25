<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Command;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;

class SetPasswordCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = ['plugin.RockSoftware/Users.Users'];

    public function testSetsAPasswordReadFromInput(): void
    {
        $users = $this->fetchTable('Users');
        $user = $users->saveOrFail($users->newEntity([
            'email' => 'person@example.test',
            'name' => 'Person',
            'password' => '!rsu:x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));

        $this->exec('rock_software_users set_password PERSON@example.test', ['a long enough password']);

        $this->assertExitSuccess();
        $hash = (string)$users->get($user->get('id'))->get('password');
        $this->assertTrue((new DefaultPasswordHasher())->check('a long enough password', $hash));
    }

    public function testRefusesAShortPassword(): void
    {
        $users = $this->fetchTable('Users');
        $users->saveOrFail($users->newEntity([
            'email' => 'person@example.test',
            'name' => 'Person',
            'password' => '!rsu:x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));

        $this->exec('rock_software_users set_password person@example.test', ['short']);

        $this->assertExitError();
        $this->assertErrorContains('at least 12 characters');
    }

    public function testRefusesAnUnknownAddress(): void
    {
        $this->exec('rock_software_users set_password nobody@example.test', ['a long enough password']);

        $this->assertExitError();
        $this->assertErrorContains('No user has the email address');
    }
}
