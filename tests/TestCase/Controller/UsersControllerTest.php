<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Controller;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Controller\UsersController;
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Service\AccessManager;
use RockSoftware\Users\Session\SignInSession;

class UsersControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.SessionEvents',
        'plugin.RockSoftware/Users.AccessChanges',
        'plugin.RockSoftware/Users.HeldRevocations',
        'plugin.RockSoftware/Users.EmergencySignIns',
        'plugin.RockSoftware/Users.UserEmails',
        'plugin.RockSoftware/Users.Users',
    ];

    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        Configure::write('RockSoftwareUsers.usersAccess', fn($identity): bool => $identity->get('email') === 'admin@example.test');
        $this->admin = $this->createUser('admin@example.test', 'Admin');
        $this->link($this->admin, 'subject-admin');
    }

    protected function tearDown(): void
    {
        Configure::write('RockSoftwareUsers.usersAccess', null);
        foreach ([UsersController::EVENT_LIST_COLUMNS, UsersController::EVENT_VIEW_SECTIONS, UsersController::EVENT_USER_ACTIONS] as $name) {
            EventManager::instance()->off($name);
        }
        parent::tearDown();
    }

    public function testAccessIsTheApplicationsDecision(): void
    {
        $this->signIn($this->createUser('someone@example.test', 'Someone'));

        $this->get('/auth/users');

        $this->assertResponseCode(403);
    }

    public function testTheListShowsWhoHasAccessByDefault(): void
    {
        $linked = $this->createUser('linked@example.test', 'Linked Person', true, ['phone' => '+358401234567']);
        $this->link($linked, 'subject-1');
        $this->fetchTable('RockSoftware/Users.SessionEvents')->record(SessionEventType::SIGNED_IN, [
            'user_id' => (int)$linked->get('id'),
            'method' => SignInSession::METHOD_OIDC,
        ]);
        $starting = $this->createUser('tom@example.test', 'Tom Starting', true, ['access_from' => DateTime::now()->addDays(7)]);
        $this->link($starting, 'subject-2');
        $this->createUser('closed@example.test', 'Closed Person', false);
        $this->createUser('left@example.test', 'Left Person', true, ['access_until' => DateTime::now()->subDays(1)]);
        $this->createUser('legacy@example.test', 'Legacy Person');
        $this->signIn($this->admin);

        $this->get('/auth/users');

        $this->assertResponseOk();
        $this->assertResponseContains('Linked Person');
        $this->assertResponseContains('+358401234567');
        $this->assertResponseContains('Tom Starting');
        $this->assertResponseContains('Starts ');
        $this->assertResponseContains('Not in Rock Software ID');
        $this->assertResponseNotContains('Closed Person', 'people with access or starting by default');
        $this->assertResponseNotContains('Left Person');

        $this->get('/auth/users?access=none');
        $this->assertResponseContains('Closed Person');
        $this->assertResponseContains('Access removed');
        $this->assertResponseContains('Left Person');
        $this->assertResponseContains('Ended ');
        $this->assertResponseNotContains('Linked Person');

        $this->get('/auth/users?access=starting');
        $this->assertResponseContains('Tom Starting');
        $this->assertResponseNotContains('Linked Person');

        $this->get('/auth/users?access=attention');
        $this->assertResponseContains('Legacy Person', 'not linked to Rock Software ID');
        $this->assertResponseNotContains('Linked Person');

        $this->get('/auth/users?access=all&q=4012345');
        $this->assertResponseContains('Linked Person', 'search covers the phone');
        $this->assertResponseNotContains('Closed Person');
    }

    public function testTheApplicationAddsColumnsSectionsAndButtons(): void
    {
        EventManager::instance()->on(UsersController::EVENT_LIST_COLUMNS, function (EventInterface $event): void {
            /** @var \ArrayObject $columns */
            $columns = $event->getData('columns');
            $columns[] = ['label' => 'Role', 'value' => fn($user): string => $user->get('email') === 'admin@example.test' ? 'Owner' : 'Viewer'];
        });
        EventManager::instance()->on(UsersController::EVENT_VIEW_SECTIONS, function (EventInterface $event): void {
            $this->assertInstanceOf(ArrayObject::class, $event->getData('sections'));
            $event->getData('sections')[] = ['title' => 'Permissions', 'element' => 'flash/info', 'data' => ['message' => 'Viewer of everything']];
        });
        EventManager::instance()->on(UsersController::EVENT_USER_ACTIONS, function (EventInterface $event): void {
            $event->getData('actions')[] = [
                'label' => 'Manage permissions',
                'url' => fn($user): ?string => $user->get('email') === 'admin@example.test' ? '/permissions/' . $user->get('id') : null,
            ];
        });
        $this->signIn($this->admin);

        $this->get('/auth/users');
        $this->assertResponseContains('<th scope="col">Role</th>');
        $this->assertResponseContains('Owner');
        $this->assertResponseContains('href="/permissions/' . $this->admin->get('id') . '"');
        $this->assertResponseContains('Manage permissions');

        $this->get('/auth/users/' . $this->admin->get('id'));
        $this->assertResponseContains('Permissions');
        $this->assertResponseContains('Viewer of everything');
        $this->assertResponseContains('href="/permissions/' . $this->admin->get('id') . '"');
    }

    public function testAnEmailConflictFadesWhenRockSoftwareIdStopsRepeatingIt(): void
    {
        $person = $this->createUser('person@example.test', 'Person');
        $this->link($person, 'subject-p');
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->updateAll(['pending_email' => 'taken@example.test', 'synced_at' => DateTime::now()], ['user_id' => $person->get('id')]);
        $this->signIn($this->admin);

        $this->get('/auth/users/' . $person->get('id'));
        $this->assertResponseContains('Email conflict');
        $this->get('/auth/users?access=attention');
        $this->assertResponseContains('Person');

        $identities->updateAll(['synced_at' => DateTime::now()->subHours(6)], ['user_id' => $person->get('id')]);
        $this->get('/auth/users/' . $person->get('id'));
        $this->assertResponseNotContains('Email conflict');
        $this->get('/auth/users?access=attention');
        $this->assertResponseNotContains('person@example.test');
    }

    public function testOtherAddressesAreShownAndFound(): void
    {
        $person = $this->createUser('person@example.test', 'Person');
        $emails = $this->fetchTable('RockSoftware/Users.UserEmails');
        $emails->replaceFor((int)$person->get('id'), ['person@other.example.test' => 'Rock Software']);
        $this->signIn($this->admin);

        $this->get('/auth/users?q=' . rawurlencode('person@other.example.test'));
        $this->assertResponseContains('person@example.test', 'found by the other address');
        $this->assertResponseContains('Also: person@other.example.test');

        $this->get('/auth/users/' . $person->get('id'));
        $this->assertResponseContains('Other addresses');
        $this->assertResponseContains('Rock Software');
        $this->assertResponseContains('used here');
    }

    public function testThePageCannotChangeAccess(): void
    {
        $person = $this->createUser('person@example.test', 'Person');
        $this->signIn($this->admin);

        $this->get('/auth/users/' . $person->get('id'));
        $this->assertResponseOk();
        $this->assertResponseNotContains('Deactivate');
        $this->assertResponseContains('Name, email addresses, phone and access dates are kept in Rock Software ID.');

        $this->post('/auth/users/' . $person->get('id') . '/deactivate');
        $this->assertResponseCode(404);
        $this->assertTrue($this->fetchTable('Users')->get($person->get('id'))->get('is_active'));
    }

    public function testEndingSessionsSignsThePersonOut(): void
    {
        $person = $this->createUser('person@example.test', 'Person');
        $this->signIn($this->admin);

        $this->get('/auth/users/' . $person->get('id') . '/end-sessions');
        $this->assertResponseCode(405);

        $this->post('/auth/users/' . $person->get('id') . '/end-sessions');
        $this->assertRedirect('/auth/users/' . $person->get('id'));

        $this->session([
            'Auth' => $person,
            'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => time() - 60],
        ]);
        $this->get('/dashboard');
        $this->assertRedirect('https://app.example.test/auth');
    }

    public function testAnEmergencyLinkIsShownOnceAndRecorded(): void
    {
        $person = $this->createUser('pekka@example.test', 'Pekka');
        $this->signIn($this->admin);

        $this->get('/auth/users/' . $person->get('id') . '/emergency-link');
        $this->assertResponseOk();
        $this->assertResponseContains('Valid for');
        $this->assertSame(0, $this->fetchTable('RockSoftware/Users.EmergencySignIns')->find()->count(), 'nothing until asked');

        $this->post('/auth/users/' . $person->get('id') . '/emergency-link', ['minutes' => 60]);
        $this->assertResponseOk();
        $this->assertResponseRegExp('#https://app\.example\.test/auth/emergency/[0-9a-f]{64}#');
        $this->assertHeader('Cache-Control', 'no-store');
        $link = $this->fetchTable('RockSoftware/Users.EmergencySignIns')->find()->firstOrFail();
        $this->assertSame((int)$person->get('id'), $link->user_id);
        $this->assertEqualsWithDelta(DateTime::now()->addMinutes(60)->getTimestamp(), $link->expires_at->getTimestamp(), 5);
        $change = $this->fetchTable('RockSoftware/Users.AccessChanges')->find()->contain(['AccessChangeTypes'])->firstOrFail();
        $this->assertSame(AccessChangeType::EMERGENCY_LINK_ISSUED, $change->access_change_type->value);
        $this->assertSame((int)$this->admin->get('id'), $change->actor_user_id);
    }

    public function testNoEmergencyLinkForSomeoneWithoutAccess(): void
    {
        $tom = $this->createUser('tom@example.test', 'Tom', true, ['access_from' => DateTime::now()->addDays(3)]);
        $this->signIn($this->admin);

        $this->get('/auth/users/' . $tom->get('id'));
        $this->assertResponseContains('Emergency links work only for people with access, from');

        $this->post('/auth/users/' . $tom->get('id') . '/emergency-link', ['minutes' => 240]);
        $this->assertRedirect('/auth/users/' . $tom->get('id'));
        $this->assertSame(0, $this->fetchTable('RockSoftware/Users.EmergencySignIns')->find()->count());
    }

    public function testHeldRemovalsAreKeptOrRemovedByAPerson(): void
    {
        $person = $this->createUser('person@example.test', 'Person');
        $access = new AccessManager();
        $this->assertSame('held', $access->requestRevocation($person, RevocationType::LEFT_GROUPS), 'half of two users');
        $this->signIn($this->admin);

        $this->get('/auth/users');
        $this->assertResponseContains('Removing access is on hold for 1 person');

        $this->get('/auth/users/held-removals');
        $this->assertResponseContains('Person');
        $this->assertResponseContains('Left the application&#039;s groups');

        $this->post('/auth/users/held-removals/reject');
        $this->assertRedirect('/auth/users');
        $this->assertTrue($this->fetchTable('Users')->get($person->get('id'))->get('is_active'));

        $this->assertSame('held', $access->requestRevocation($person, RevocationType::REMOVED));
        $this->post('/auth/users/held-removals/approve');
        $this->assertFalse($this->fetchTable('Users')->get($person->get('id'))->get('is_active'));

        $this->get('/auth/users/' . $person->get('id'));
        $this->assertResponseContains('Held removal approved');
        $this->assertResponseContains('Access removed: Removed from the application in Rock Software ID');
    }

    private function signIn(EntityInterface $user): void
    {
        $this->session([
            'Auth' => $user,
            'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => time()],
        ]);
    }

    /**
     * @param array<string, mixed> $extra More columns.
     */
    private function createUser(string $email, string $name, bool $active = true, array $extra = []): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'name' => $name,
            'password' => '!rsu:x',
            'is_active' => $active,
        ] + $extra, ['accessibleFields' => ['*' => true], 'validate' => false]));
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
