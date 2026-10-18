<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Middleware;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Service\AccessManager;
use RockSoftware\Users\Service\Scim\ScimUsers;
use RockSoftware\Users\Session\SignInSession;

/**
 * The SCIM endpoint as Rock Software ID (Authentik's SCIM client) calls it:
 * every request over HTTP, through the plugin's middleware.
 */
class ScimMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    private const TOKEN = 'scim-token-0123456789abcdefghijklmnopqrstuvwxyz';

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.SessionEvents',
        'plugin.RockSoftware/Users.AccessChanges',
        'plugin.RockSoftware/Users.HeldRevocations',
        'plugin.RockSoftware/Users.ScimGroups',
        'plugin.RockSoftware/Users.ScimGroupMembers',
        'plugin.RockSoftware/Users.Users',
    ];

    /**
     * @var array<array<string, mixed>>
     */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $settings->store([
            'issuer_url' => 'https://id.example.test/application/o/app/',
            'client_id' => 'app',
            'client_secret' => 'secret',
            'is_enabled' => true,
            'scim_token_hash' => hash('sha256', self::TOKEN),
        ]);
        $this->events = [];
        foreach ([AccessManager::EVENT_GRANTED, AccessManager::EVENT_REVOKED, AccessManager::EVENT_ALERT] as $name) {
            EventManager::instance()->on($name, function (EventInterface $event) use ($name): void {
                $this->events[] = ['name' => $name] + (array)$event->getData();
            });
        }
    }

    protected function tearDown(): void
    {
        foreach ([AccessManager::EVENT_GRANTED, AccessManager::EVENT_REVOKED, AccessManager::EVENT_ALERT] as $name) {
            EventManager::instance()->off($name);
        }
        parent::tearDown();
    }

    public function testEveryRequestNeedsTheToken(): void
    {
        $this->configRequest(['headers' => ['Accept' => 'application/scim+json']]);
        $this->get('/auth/scim/v2/Users');
        $this->assertResponseCode(401);

        $this->configRequest(['headers' => ['Authorization' => 'Bearer wrong-token-0123456789abcdefghijklmnopqrstuv']]);
        $this->get('/auth/scim/v2/Users');
        $this->assertResponseCode(401);

        $this->fetchTable('RockSoftware/Users.Settings')->store(['scim_token_hash' => null]);
        $this->scim('GET', '/ServiceProviderConfig');
        $this->assertResponseCode(401, 'no token stored means SCIM is not set up');
    }

    public function testServiceProviderConfigOffersPatchAndFilters(): void
    {
        $this->scim('GET', '/ServiceProviderConfig');

        $this->assertResponseOk();
        $this->assertContentType('application/scim+json');
        $body = $this->body();
        $this->assertTrue($body['patch']['supported']);
        $this->assertTrue($body['filter']['supported']);
        $this->assertFalse($body['bulk']['supported']);
        $this->assertSame(0, $body['bulk']['maxOperations']);
    }

    public function testAPersonIsCreatedBeforeTheirFirstDay(): void
    {
        $from = DateTime::now()->addDays(7)->setTimezone('Europe/Helsinki');

        $this->scim('POST', '/Users', $this->person('tom', ['accessFrom' => $from->toIso8601String()]));

        $this->assertResponseCode(201);
        $body = $this->body();
        $this->assertNotEmpty($body['id']);
        $this->assertSame('uid-tom', $body['externalId']);
        $user = $this->fetchTable('Users')->get((int)$body['id']);
        $this->assertSame('tom@example.test', $user->get('email'));
        $this->assertSame('Tom Virtanen', $user->get('name'));
        $this->assertSame('+358401234567', $user->get('phone'));
        $this->assertTrue($user->get('is_active'));
        $this->assertSame($from->getTimestamp(), $user->get('access_from')->getTimestamp());
        $identity = $this->fetchTable('RockSoftware/Users.Identities')->find()->where(['user_id' => $user->get('id')])->firstOrFail();
        $this->assertSame('uid-tom', $identity->external_id);
        $this->assertSame('tom', $identity->user_name);
        $this->assertSame(AccessManager::EVENT_GRANTED, $this->events[0]['name']);

        // He cannot sign in yet, with any method.
        $this->session(['Auth' => $user, 'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => time()]]);
        $this->get('/dashboard');
        $this->assertRedirect('https://app.example.test/auth');
    }

    public function testCreatingTwiceUpdatesTheSameRow(): void
    {
        $this->scim('POST', '/Users', $this->person('tom'));
        $id = $this->body()['id'];

        $this->scim('POST', '/Users', $this->person('tom', [], ['name' => ['formatted' => 'Tom V.']]));

        $this->assertResponseCode(201);
        $this->assertSame($id, $this->body()['id']);
        $this->assertSame(1, $this->fetchTable('Users')->find()->count());
        $this->assertSame('Tom V.', $this->fetchTable('Users')->get((int)$id)->get('name'));
    }

    public function testAnExistingUserIsLinkedByEmailNotDuplicated(): void
    {
        $existing = $this->createUser('marko@example.test', 'Marko');

        $this->scim('POST', '/Users', $this->person('marko'));

        $this->assertResponseCode(201);
        $this->assertSame((string)$existing->get('id'), $this->body()['id']);
        $this->assertSame(1, $this->fetchTable('Users')->find()->count());
    }

    public function testFindsUsersByUserNameAndPages(): void
    {
        $this->scim('POST', '/Users', $this->person('tom'));
        $this->scim('POST', '/Users', $this->person('anna'));

        $this->scim('GET', '/Users?filter=' . rawurlencode('userName eq "anna"'));
        $this->assertResponseOk();
        $body = $this->body();
        $this->assertSame(1, $body['totalResults']);
        $this->assertSame('anna@example.test', $body['Resources'][0]['emails'][0]['value']);

        $this->scim('GET', '/Users?startIndex=1&count=1');
        $body = $this->body();
        $this->assertSame(2, $body['totalResults']);
        $this->assertSame(1, $body['itemsPerPage']);

        $this->scim('GET', '/Users?filter=' . rawurlencode('emails co "x"'));
        $this->assertResponseCode(400);
    }

    public function testReplacingKeepsDetailsCurrentAndRecordsWhatChanged(): void
    {
        $this->scim('POST', '/Users', $this->person('tom'));
        $id = (int)$this->body()['id'];
        $until = DateTime::now()->addMonths(3);

        $this->scim('PUT', '/Users/' . $id, $this->person('tom', ['accessUntil' => $until->toIso8601String()], [
            'emails' => [['value' => 'tom.virtanen@example.test', 'primary' => true]],
            'phoneNumbers' => [['value' => '+358 50 111 2222', 'type' => 'mobile']],
        ]));

        $this->assertResponseOk();
        $user = $this->fetchTable('Users')->get($id);
        $this->assertSame('tom.virtanen@example.test', $user->get('email'));
        $this->assertSame('+358501112222', $user->get('phone'));
        $this->assertSame($until->getTimestamp(), $user->get('access_until')->getTimestamp());
        $this->assertSame(
            [AccessChangeType::DETAILS_UPDATED, AccessChangeType::GRANTED],
            $this->historyTypes($id),
        );
    }

    public function testAnEmailAnotherUserHasIsRefusedAndShown(): void
    {
        $someone = $this->createUser('taken@example.test', 'Someone Else');
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $someone->get('id'), 'external_id' => 'uid-someone'],
            ['accessibleFields' => ['*' => true]],
        ));
        $this->scim('POST', '/Users', $this->person('tom'));
        $id = (int)$this->body()['id'];

        $this->scim('PUT', '/Users/' . $id, $this->person('tom', [], ['emails' => [['value' => 'taken@example.test', 'primary' => true]]]));

        $this->assertResponseCode(409);
        $this->assertSame('uniqueness', $this->body()['scimType']);
        $this->assertSame('tom@example.test', $this->fetchTable('Users')->get($id)->get('email'));
        $identity = $this->fetchTable('RockSoftware/Users.Identities')->find()->where(['user_id' => $id])->firstOrFail();
        $this->assertSame('taken@example.test', $identity->get('pending_email'));
        $this->assertContains(AccessChangeType::EMAIL_CONFLICT, $this->historyTypes($id));
        $alerts = array_values(array_filter($this->events, fn($e) => $e['name'] === AccessManager::EVENT_ALERT));
        $this->assertSame(AccessManager::ALERT_EMAIL_CONFLICT, $alerts[0]['alert']);

        $this->scim('POST', '/Users', $this->person('new', [], ['emails' => [['value' => 'taken@example.test']]]));
        $this->assertResponseCode(409, 'a new person with a taken address is refused too');
    }

    public function testDeactivatingRevokesButNeverDeletes(): void
    {
        $this->createUser('other1@example.test', 'Other 1');
        $this->createUser('other2@example.test', 'Other 2');
        $this->scim('POST', '/Users', $this->person('tom'));
        $id = (int)$this->body()['id'];

        $this->scim('PUT', '/Users/' . $id, $this->person('tom', [], ['active' => false]));

        $this->assertResponseOk();
        $this->assertFalse($this->body()['active']);
        $user = $this->fetchTable('Users')->get($id);
        $this->assertFalse($user->get('is_active'));
        $revoked = array_values(array_filter($this->events, fn($e) => $e['name'] === AccessManager::EVENT_REVOKED));
        $this->assertSame('deactivated', $revoked[0]['revocation']);

        // Rehired: the same row, the same id, access again.
        $this->scim('PUT', '/Users/' . $id, $this->person('tom'));
        $this->assertTrue($this->fetchTable('Users')->get($id)->get('is_active'));
        $this->assertSame(3, $this->fetchTable('Users')->find()->count());
    }

    public function testDeleteRevokesAndKeepsTheRow(): void
    {
        $this->createUser('other1@example.test', 'Other 1');
        $this->createUser('other2@example.test', 'Other 2');
        $this->scim('POST', '/Users', $this->person('tom'));
        $id = (int)$this->body()['id'];

        $this->scim('DELETE', '/Users/' . $id);

        $this->assertResponseCode(204);
        $this->assertFalse($this->fetchTable('Users')->get($id)->get('is_active'));

        $this->scim('POST', '/Users', $this->person('tom'));
        $this->assertSame((string)$id, $this->body()['id'], 'pushed again, the same row');
        $this->assertTrue($this->fetchTable('Users')->get($id)->get('is_active'));
    }

    public function testLeavingTheLastGroupRevokesAndComingBackGrants(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->createUser($name . '@example.test', $name);
        }
        $this->scim('POST', '/Users', $this->person('tom'));
        $tom = $this->body()['id'];
        $this->scim('POST', '/Groups', ['displayName' => 'app-staff', 'externalId' => 'g-1', 'members' => [['value' => $tom]]]);
        $this->assertResponseCode(201);
        $group = $this->body()['id'];
        $this->assertSame([['value' => $tom]], $this->body()['members']);

        $this->scim('PATCH', '/Groups/' . $group, ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [
            ['op' => 'remove', 'path' => 'members[value eq "' . $tom . '"]'],
        ]]);

        $this->assertResponseOk();
        $this->assertFalse($this->fetchTable('Users')->get((int)$tom)->get('is_active'), 'at once, not at the next full sync');

        $this->scim('PATCH', '/Groups/' . $group, ['Operations' => [
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => $tom]]],
        ]]);
        $this->assertTrue($this->fetchTable('Users')->get((int)$tom)->get('is_active'));
    }

    public function testComingBackIntoAGroupDoesNotReopenADeactivatedAccount(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->createUser($name . '@example.test', $name);
        }
        $this->scim('POST', '/Users', $this->person('tom'));
        $tom = $this->body()['id'];
        $this->scim('PUT', '/Users/' . $tom, $this->person('tom', [], ['active' => false]));
        $this->scim('POST', '/Groups', ['displayName' => 'app-staff', 'members' => [['value' => $tom]]]);

        $this->assertFalse($this->fetchTable('Users')->get((int)$tom)->get('is_active'));
    }

    public function testGroupsAreFoundReplacedAndDeleted(): void
    {
        $this->scim('POST', '/Groups', ['displayName' => 'app-staff', 'externalId' => 'g-1']);
        $group = $this->body()['id'];

        $this->scim('GET', '/Groups?filter=' . rawurlencode('displayName eq "app-staff"'));
        $this->assertSame($group, $this->body()['Resources'][0]['id']);

        $this->scim('PATCH', '/Groups/' . $group, ['Operations' => [
            ['op' => 'replace', 'value' => ['displayName' => 'app-team', 'externalId' => 'g-1']],
        ]]);
        $this->assertResponseOk();
        $this->assertSame('app-team', $this->body()['displayName']);

        $this->scim('PUT', '/Groups/' . $group, ['displayName' => 'app-team']);
        $this->assertResponseOk();
        $this->assertSame('app-team', $this->body()['displayName']);

        $this->scim('DELETE', '/Groups/' . $group);
        $this->assertResponseCode(204);
        $this->scim('GET', '/Groups/' . $group);
        $this->assertResponseCode(404);
    }

    public function testRemovingHalfTheUsersIsHeld(): void
    {
        $this->scim('POST', '/Users', $this->person('tom'));
        $tom = (int)$this->body()['id'];
        $this->scim('POST', '/Users', $this->person('anna'));
        $anna = (int)$this->body()['id'];
        foreach (['a', 'b'] as $name) {
            $this->createUser($name . '@example.test', $name);
        }

        $this->scim('DELETE', '/Users/' . $tom);
        $this->assertFalse($this->fetchTable('Users')->get($tom)->get('is_active'), 'one of four goes');

        $this->scim('DELETE', '/Users/' . $anna);
        $this->assertResponseCode(204, 'Rock Software ID is answered as usual');
        $this->assertTrue($this->fetchTable('Users')->get($anna)->get('is_active'), 'two of four within 15 minutes: held');
        $this->assertSame(1, $this->fetchTable('RockSoftware/Users.HeldRevocations')->find()->count());
        $alerts = array_values(array_filter($this->events, fn($e) => $e['name'] === AccessManager::EVENT_ALERT));
        $this->assertSame(AccessManager::ALERT_REVOCATIONS_HELD, $alerts[0]['alert']);

        // Rock Software ID gives her back: the held removal is dropped.
        $this->scim('POST', '/Users', $this->person('anna'));
        $this->assertSame([], $this->fetchTable('RockSoftware/Users.HeldRevocations')->pending());
    }

    public function testAHeldDeactivationIsAnsweredAsSent(): void
    {
        $this->scim('POST', '/Users', $this->person('tom'));
        $tom = (int)$this->body()['id'];
        $this->createUser('a@example.test', 'a');

        $this->scim('PUT', '/Users/' . $tom, $this->person('tom', [], ['active' => false]));

        $this->assertResponseOk();
        $this->assertTrue($this->fetchTable('Users')->get($tom)->get('is_active'), 'held: one of two');
        $this->assertFalse($this->body()['active'], 'answered as sent, so the later active:true is a change');

        $this->scim('PUT', '/Users/' . $tom, $this->person('tom'));
        $this->assertTrue($this->body()['active']);
        $this->assertSame([], $this->fetchTable('RockSoftware/Users.HeldRevocations')->pending());
    }

    public function testASystemUserIsNeverTouched(): void
    {
        $r2d2 = $this->createUser('r2d2@example.test', 'R2D2');
        Configure::write('RockSoftwareUsers.systemUsers', [(int)$r2d2->get('id')]);
        try {
            $this->scim('POST', '/Users', $this->person('r2d2'));
            $this->assertResponseCode(409);
            $this->assertSame('R2D2', $this->fetchTable('Users')->get($r2d2->get('id'))->get('name'));
        } finally {
            Configure::write('RockSoftwareUsers.systemUsers', []);
        }
    }

    public function testALongNameIsCutToTheColumn(): void
    {
        $this->scim('POST', '/Users', $this->person('tom', [], ['name' => ['formatted' => str_repeat('Virtanen ', 30)]]));

        $this->assertResponseCode(201);
        $this->assertSame(100, mb_strlen($this->fetchTable('Users')->get((int)$this->body()['id'])->get('name')));
    }

    public function testABadPayloadIsRefused(): void
    {
        $this->scim('POST', '/Users', ['userName' => 'x', 'emails' => [['value' => 'x@example.test']]]);
        $this->assertResponseCode(400);

        $this->scim('POST', '/Users', $this->person('tom', ['accessFrom' => 'next monday']));
        $this->assertResponseCode(400);
        $this->assertSame(0, $this->fetchTable('Users')->find()->count());

        $this->scim('PUT', '/Users/999', $this->person('tom'));
        $this->assertResponseCode(404);
    }

    /**
     * @param array<string, mixed> $access The access extension.
     * @param array<string, mixed> $override Top-level overrides.
     * @return array<string, mixed>
     */
    private function person(string $name, array $access = [], array $override = []): array
    {
        $payload = [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User', ScimUsers::ACCESS_SCHEMA],
            'userName' => $name,
            'externalId' => 'uid-' . $name,
            'name' => ['formatted' => $name === 'tom' ? 'Tom Virtanen' : ucfirst($name)],
            'displayName' => ucfirst($name),
            'active' => true,
            'emails' => [['value' => $name . '@example.test', 'type' => 'other', 'primary' => true]],
            'phoneNumbers' => [['value' => '+358401234567', 'type' => 'mobile']],
        ];
        if ($access !== []) {
            $payload[ScimUsers::ACCESS_SCHEMA] = $access + ['accessFrom' => null, 'accessUntil' => null];
        }

        return $override + $payload;
    }

    /**
     * @param array<string, mixed>|null $body The JSON body.
     */
    private function scim(string $method, string $path, ?array $body = null): void
    {
        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . self::TOKEN,
                'Accept' => 'application/scim+json',
                'Content-Type' => 'application/scim+json',
            ],
        ]);
        $json = $body === null ? '' : (string)json_encode($body);
        $url = '/auth/scim/v2' . $path;
        match ($method) {
            'GET' => $this->get($url),
            'POST' => $this->post($url, $json),
            'PUT' => $this->put($url, $json),
            'PATCH' => $this->patch($url, $json),
            'DELETE' => $this->delete($url),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    /**
     * @return array<string>
     */
    private function historyTypes(int $userId): array
    {
        return array_map(
            fn($change): string => $change->access_change_type->value,
            $this->fetchTable('RockSoftware/Users.AccessChanges')->forUser($userId),
        );
    }

    private function createUser(string $email, string $name): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'name' => $name,
            'password' => '!rsu:x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
    }
}
