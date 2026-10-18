<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;

/**
 * `scim_precheck`: what switching SCIM on would do, from the people JSON Rock
 * Software ID exports. It changes nothing.
 */
class ScimPrecheckCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.Users',
    ];

    public function testShowsWhatSwitchingOnWouldDo(): void
    {
        $linked = $this->createUser('linked@example.test');
        $this->link($linked, 'uid-linked');
        $this->createUser('anna@example.test');
        $this->createUser('marko@rocksoftware.test');
        $taken = $this->createUser('taken@example.test');
        $this->link($taken, 'uid-someone-else');
        $leaver = $this->createUser('leaver@example.test');
        $this->link($leaver, 'uid-leaver');
        $this->createUser('legacy@example.test');
        $file = TMP . 'precheck-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, (string)json_encode(['application' => 'msg', 'people' => [
            ['externalId' => 'uid-linked', 'userName' => 'linked', 'email' => 'linked@example.test'],
            ['externalId' => 'uid-anna', 'userName' => 'anna', 'email' => 'ANNA@example.test'],
            ['externalId' => 'uid-marko', 'userName' => 'marko', 'email' => 'marko@konekallio.test', 'otherEmails' => ['marko@rocksoftware.test']],
            ['externalId' => 'uid-tom', 'userName' => 'tom', 'email' => 'taken@example.test'],
            ['externalId' => 'uid-new', 'userName' => 'new', 'email' => 'new@example.test'],
        ]]));
        try {
            $this->exec('rock_software_users scim_precheck --file ' . $file);
        } finally {
            unlink($file);
        }

        $this->assertExitSuccess();
        $this->assertOutputContains('Would duplicate (fix first');
        $this->assertOutputRegExp('/marko@konekallio\.test .*already #\d+ marko@rocksoftware\.test/');
        $this->assertOutputRegExp('/Address taken by another linked user \(fix first\): 1/');
        $this->assertOutputRegExp('/they would lose access \(fix first if wrong\): 2\n/');
        $this->assertOutputRegExp('/  - #\d+ leaver@example\.test/');
        $this->assertOutputRegExp('/linked to their existing row by email address: 1\n  - anna@example\.test/');
        $this->assertOutputRegExp('/Will be created as new users: 1\n  - new@example\.test/');
        $this->assertOutputRegExp('/not in Rock Software ID \(stay without a link\): 1\n  - #\d+ legacy@example\.test/');
        $this->assertErrorContains('4 to fix before SCIM is switched on.');
        $this->assertSame(3, $this->fetchTable('RockSoftware/Users.Identities')->find()->count(), 'nothing changed');
    }

    public function testRefusesSomethingElse(): void
    {
        $file = TMP . 'precheck-bad.json';
        file_put_contents($file, '{"nope": true}');
        try {
            $this->exec('rock_software_users scim_precheck --file ' . $file);
        } finally {
            unlink($file);
        }

        $this->assertExitError();
    }

    private function createUser(string $email): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'password' => '!rsu:x',
            'name' => $email,
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
    }

    private function link(EntityInterface $user, string $subject): void
    {
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $user->get('id'), 'external_id' => $subject],
            ['accessibleFields' => ['*' => true]],
        ));
    }
}
