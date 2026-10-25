<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Model\Entity\AccessChangeType;

class EmergencySignInCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.EmergencySignIns',
        'plugin.RockSoftware/Users.AccessChanges',
        'plugin.RockSoftware/Users.Users',
    ];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = TMP . 'emergency-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testWritesTheLinkToAFileAndNeverPrintsIt(): void
    {
        $user = $this->createUser('admin@example.test');
        $file = $this->dir . '/links.txt';

        $this->exec('rock_software_users emergency_sign_in admin@example.test', [$file]);

        $this->assertExitSuccess();
        $this->assertOutputNotContains('/auth/emergency/');
        $this->assertOutputContains('Wrote 1 link(s)');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertMatchesRegularExpression(
            '#^admin@example\.test\tadmin@example\.test\thttps://app\.example\.test/auth/emergency/[0-9a-f]{64}$#m',
            (string)file_get_contents($file),
        );
        $link = $this->fetchTable('RockSoftware/Users.EmergencySignIns')->find()->firstOrFail();
        $this->assertSame($user->id, $link->user_id);
        $this->assertEqualsWithDelta(DateTime::now()->addHours(4)->getTimestamp(), $link->expires_at->getTimestamp(), 5, 'four hours by default');
        $change = $this->fetchTable('RockSoftware/Users.AccessChanges')->find()->contain(['AccessChangeTypes'])->firstOrFail();
        $this->assertSame(AccessChangeType::EMERGENCY_LINK_ISSUED, $change->access_change_type->value);
    }

    public function testAllWritesOneLinkPerUserWithAccess(): void
    {
        $this->createUser('a@example.test');
        $this->createUser('b@example.test');
        $this->createUser('gone@example.test', false);
        $this->createUser('later@example.test', true, ['access_from' => DateTime::now()->addDays(2)]);
        $file = $this->dir . '/all.txt';

        $this->exec('rock_software_users emergency_sign_in --all --valid 30m --file ' . $file);

        $this->assertExitSuccess();
        $contents = (string)file_get_contents($file);
        $this->assertSame(2, preg_match_all('#/auth/emergency/[0-9a-f]{64}#', $contents));
        $this->assertStringContainsString('a@example.test', $contents);
        $this->assertStringNotContainsString('gone@example.test', $contents);
        $this->assertStringNotContainsString('later@example.test', $contents);
        $link = $this->fetchTable('RockSoftware/Users.EmergencySignIns')->find()->firstOrFail();
        $this->assertEqualsWithDelta(DateTime::now()->addMinutes(30)->getTimestamp(), $link->expires_at->getTimestamp(), 5);
    }

    public function testAnExistingFileIsNeverOverwritten(): void
    {
        $this->createUser('admin@example.test');
        $file = $this->dir . '/taken.txt';
        file_put_contents($file, 'keep me');

        $this->exec('rock_software_users emergency_sign_in admin@example.test --file ' . $file);

        $this->assertExitError();
        $this->assertErrorContains('never overwritten');
        $this->assertSame('keep me', file_get_contents($file));
        $this->assertSame(0, $this->fetchTable('RockSoftware/Users.EmergencySignIns')->find()->count());
    }

    public function testNoLinkForSomeoneWithoutAccess(): void
    {
        $this->createUser('ended@example.test', true, ['access_until' => DateTime::now()->subHours(1)]);

        $this->exec('rock_software_users emergency_sign_in ended@example.test --file ' . $this->dir . '/x.txt');

        $this->assertExitError();
        $this->assertErrorContains('no access now');
        $this->assertFileDoesNotExist($this->dir . '/x.txt');
    }

    public function testValidityIsBounded(): void
    {
        $this->createUser('admin@example.test');

        $this->exec('rock_software_users emergency_sign_in admin@example.test --valid 3d --file ' . $this->dir . '/x.txt');

        $this->assertExitError();
        $this->assertErrorContains('--valid');
    }

    public function testCreateMakesTheUserWhenDebugIsOn(): void
    {
        Configure::write('debug', true);
        try {
            $this->exec('rock_software_users emergency_sign_in agent@example.test --create --file ' . $this->dir . '/agent.txt');
        } finally {
            Configure::write('debug', false);
        }

        $this->assertExitSuccess();
        $this->assertStringContainsString('/auth/emergency/', (string)file_get_contents($this->dir . '/agent.txt'));
        $this->assertSame(1, $this->fetchTable('Users')->find()->where(['email' => 'agent@example.test'])->count());
    }

    public function testCreateIsRefusedInProduction(): void
    {
        Configure::write('debug', false);

        $this->exec('rock_software_users emergency_sign_in agent@example.test --create --file ' . $this->dir . '/x.txt');

        $this->assertExitError();
        $this->assertErrorContains('debug off');
        $this->assertSame(0, $this->fetchTable('Users')->find()->where(['email' => 'agent@example.test'])->count());
    }

    public function testUnknownEmailFails(): void
    {
        $this->exec('rock_software_users emergency_sign_in nobody@example.test --file ' . $this->dir . '/x.txt');

        $this->assertExitError();
    }

    /**
     * @param array<string, mixed> $extra More columns.
     */
    private function createUser(string $email, bool $active = true, array $extra = []): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'password' => 'hashed',
            'name' => $email,
            'is_active' => $active,
        ] + $extra, ['accessibleFields' => ['*' => true], 'validate' => false]));
    }
}
