<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Service\AccessManager;

/**
 * `restore_access` and `revocations`: the way back in, and the held removals,
 * from the server.
 */
class AccessCommandsTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.AccessChanges',
        'plugin.RockSoftware/Users.HeldRevocations',
        'plugin.RockSoftware/Users.Users',
    ];

    public function testRestoreAccessGivesOnePersonAccessBack(): void
    {
        $user = $this->createUser('marko@example.test', false, ['access_until' => DateTime::now()->subDays(1)]);

        $this->exec('rock_software_users restore_access MARKO@example.test');

        $this->assertExitSuccess();
        $this->assertOutputContains('next push overrules');
        $restored = $this->fetchTable('Users')->get($user->get('id'));
        $this->assertTrue($restored->get('is_active'));
        $this->assertNull($restored->get('access_until'), 'inside their dates again');
        $change = $this->fetchTable('RockSoftware/Users.AccessChanges')->find()->contain(['AccessChangeTypes'])->firstOrFail();
        $this->assertSame(AccessChangeType::ACCESS_RESTORED, $change->access_change_type->value);
    }

    public function testRestoreAccessDropsAHeldRemoval(): void
    {
        $user = $this->createUser('only@example.test');
        $this->assertSame('held', (new AccessManager())->requestRevocation($user, RevocationType::REMOVED), 'the only user');

        $this->exec('rock_software_users restore_access only@example.test');

        $this->assertExitSuccess();
        $this->assertSame([], $this->fetchTable('RockSoftware/Users.HeldRevocations')->pending());
    }

    public function testRestoreAccessForAnUnknownAddressFails(): void
    {
        $this->exec('rock_software_users restore_access nobody@example.test');

        $this->assertExitError();
    }

    public function testRevocationsAreListedApprovedAndRejected(): void
    {
        $user = $this->createUser('only@example.test');
        $access = new AccessManager();
        $access->requestRevocation($user, RevocationType::LEFT_GROUPS);

        $this->exec('rock_software_users revocations');
        $this->assertExitSuccess();
        $this->assertOutputContains('only@example.test');
        $this->assertOutputContains('1 on hold');

        $this->exec('rock_software_users revocations reject');
        $this->assertOutputContains('1 people keep their access.');
        $this->assertTrue($this->fetchTable('Users')->get($user->get('id'))->get('is_active'));

        $access->requestRevocation($user, RevocationType::LEFT_GROUPS);
        $this->exec('rock_software_users revocations approve');
        $this->assertOutputContains('Access removed for 1 people.');
        $this->assertFalse($this->fetchTable('Users')->get($user->get('id'))->get('is_active'));

        $this->exec('rock_software_users revocations');
        $this->assertOutputContains('No removal is on hold.');
    }

    /**
     * @param array<string, mixed> $extra More columns.
     */
    private function createUser(string $email, bool $active = true, array $extra = []): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'password' => '!rsu:x',
            'name' => $email,
            'is_active' => $active,
        ] + $extra, ['accessibleFields' => ['*' => true], 'validate' => false]));
    }
}
