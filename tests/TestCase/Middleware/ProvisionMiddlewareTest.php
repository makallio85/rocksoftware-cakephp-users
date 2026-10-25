<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Middleware;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Service\Provisioning;
use RockSoftware\Users\Test\TestCase\FakeProvider;
use RockSoftware\Users\UsersPlugin;

/**
 * `POST /auth/provision`: Rock Software ID hands the application its
 * connection. Every way a request can be forged, replayed or aimed elsewhere
 * is refused before anything is stored.
 */
class ProvisionMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    private const SECRET = 'provisioned-secret';

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Provisionings',
        'plugin.RockSoftware/Users.Users',
    ];

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        Client::clearMockResponses();
        $this->provider = new FakeProvider();
        $this->provider->serveMetadata();
        Configure::write('RockSoftwareUsers.provisioning', ['issuerPrefix' => 'https://id.example.test/application/o/']);
        Log::setConfig('provision_test', ['className' => ArrayLog::class, 'levels' => ['notice', 'warning', 'error']]);
    }

    protected function tearDown(): void
    {
        Client::clearMockResponses();
        Configure::write('RockSoftwareUsers.provisioning', UsersPlugin::DEFAULTS['provisioning']);
        EventManager::instance()->off(Provisioning::EVENT);
        Log::drop('provision_test');
        parent::tearDown();
    }

    public function testStoresConnectionWithoutCsrfOrSignIn(): void
    {
        $events = [];
        EventManager::instance()->on(Provisioning::EVENT, function (EventInterface $event) use (&$events): void {
            $events[] = $event->getData();
        });

        $this->provision($this->provider->provisioningToken());

        $this->assertResponseCode(204);
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $setting = $settings->enabled();
        $this->assertNotNull($setting);
        $this->assertSame(FakeProvider::ISSUER, $setting->issuer_url);
        $this->assertSame(FakeProvider::CLIENT_ID, $setting->client_id);
        $this->assertSame('app-staff', $setting->required_group);
        $this->assertSame(self::SECRET, $settings->clientSecret($setting));
        $this->assertNotSame(self::SECRET, $setting->client_secret, 'The secret is stored encrypted.');
        $this->assertSame(1, $this->fetchTable('RockSoftware/Users.Provisionings')->find()->count());
        $this->assertCount(1, $events);
        $this->assertSame(FakeProvider::CLIENT_ID, $events[0]['clientId']);
    }

    public function testStoresOnlyTheHashOfTheScimToken(): void
    {
        $token = str_repeat('Ab3', 21) . 'x';

        $this->provision($this->provider->provisioningToken(), ['scim_token' => $token]);

        $this->assertResponseCode(204);
        $setting = $this->fetchTable('RockSoftware/Users.Settings')->enabled();
        $this->assertSame(hash('sha256', $token), $setting->scim_token_hash);
        $this->assertStringNotContainsString($token, (string)json_encode($setting->toArray()));
    }

    public function testRefusesAMalformedScimToken(): void
    {
        $this->provision($this->provider->provisioningToken(), ['scim_token' => 'short']);

        $this->assertResponseCode(400);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->enabled());
    }

    public function testReplacesAnExistingConnectionInsideAnOpenedWindow(): void
    {
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $settings->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => 'old-secret',
            'required_group' => 'old-group',
            'is_enabled' => false,
        ]);
        $settings->openProvisioning(60);

        $this->provision($this->provider->provisioningToken());

        $this->assertResponseCode(204);
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $this->assertSame(1, $settings->find()->count());
        $setting = $settings->enabled();
        $this->assertSame(self::SECRET, $settings->clientSecret($setting));
        $this->assertSame('app-staff', $setting->required_group);
        $this->assertNull($setting->provisioning_open_until, 'the window closes once used');
    }

    public function testClosedOnceConnected(): void
    {
        $this->provision($this->provider->provisioningToken());
        $this->assertResponseCode(204);

        // A fresh, otherwise valid token: refused, because the application is connected.
        $this->provision($this->provider->provisioningToken(), ['client_secret' => 'attacker-secret']);

        $this->assertResponseCode(401);
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $this->assertSame(self::SECRET, $settings->clientSecret($settings->enabled()));
        $this->assertStringContainsString('closed', implode("\n", Log::engine('provision_test')->read()));
    }

    public function testClosedForAConnectionMadeByHand(): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => 'typed-secret',
            'is_enabled' => true,
        ]);

        $this->provision($this->provider->provisioningToken());

        $this->assertResponseCode(401);
    }

    public function testClosedAfterTheWindowEnds(): void
    {
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $settings->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => 'old-secret',
            'is_enabled' => true,
        ]);
        $setting = $settings->current();
        $setting->provisioning_open_until = DateTime::now()->subMinutes(1);
        $settings->saveOrFail($setting);

        $this->provision($this->provider->provisioningToken());

        $this->assertResponseCode(401);
    }

    public function testRefusesReplayedToken(): void
    {
        $token = $this->provider->provisioningToken();
        $this->provision($token);
        $this->assertResponseCode(204);

        $this->provision($token, ['client_secret' => 'attacker-secret']);

        $this->assertResponseCode(401);
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $this->assertSame(self::SECRET, $settings->clientSecret($settings->enabled()));
    }

    public function testRefusesUntrustedIssuerWithoutFetchingIt(): void
    {
        // Nothing is served for this host: a fetch would throw, and the
        // assertion below proves none was attempted.
        $this->provision($this->provider->provisioningToken(['iss' => 'https://evil.example/application/o/app/']), [
            'issuer' => 'https://evil.example/application/o/app/',
        ]);

        $this->assertResponseCode(401);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
        $this->assertStringContainsString('untrusted issuer', implode("\n", Log::engine('provision_test')->read()));
    }

    public function testRefusesIssuerNestedOrOutsideThePrefix(): void
    {
        foreach (
            [
                'https://id.example.test/application/o/app/../other/',
                'https://id.example.test/application/o/a/b/',
                'https://id.example.test/application/other/',
                'https://id.example.test.evil.example/application/o/app/',
                'http://id.example.test/application/o/app/',
            ] as $issuer
        ) {
            $this->assertFalse(
                Provisioning::trustedIssuer($issuer, 'https://id.example.test/application/o/'),
                $issuer,
            );
        }
        $this->assertTrue(Provisioning::trustedIssuer(FakeProvider::ISSUER, 'https://id.example.test/application/o/'));
    }

    public function testRefusesTokenMadeForAnotherClient(): void
    {
        $this->provision($this->provider->provisioningToken(['aud' => 'other-client', 'azp' => 'other-client']));

        $this->assertResponseCode(401);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
    }

    public function testRefusesTokenFromAnotherIssuerOfTheSameProvider(): void
    {
        $this->provision($this->provider->provisioningToken(['iss' => 'https://id.example.test/application/o/other/']));

        $this->assertResponseCode(401);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
    }

    public function testRefusesTokenIssuedToAnotherAccount(): void
    {
        $this->provision($this->provider->provisioningToken(['preferred_username' => 'someone']));

        $this->assertResponseCode(401);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
    }

    public function testRefusesOldToken(): void
    {
        $this->provision($this->provider->provisioningToken(['iat' => time() - 600]));

        $this->assertResponseCode(401);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
    }

    public function testRefusesTokenWithoutId(): void
    {
        $this->provision($this->provider->provisioningToken(['jti' => '']));

        $this->assertResponseCode(401);
    }

    public function testRefusesForeignSignature(): void
    {
        $this->provision($this->provider->provisioningToken([], FakeProvider::foreignKey()));

        $this->assertResponseCode(401);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
    }

    public function testRefusesKeysOnAnotherHost(): void
    {
        Client::clearMockResponses();
        Client::addMockResponse('GET', FakeProvider::ISSUER . '.well-known/openid-configuration', new Client\Response(
            ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
            (string)json_encode([
                'issuer' => FakeProvider::ISSUER,
                'authorization_endpoint' => 'https://id.example.test/application/o/authorize/',
                'token_endpoint' => 'https://id.example.test/application/o/token/',
                'jwks_uri' => 'https://evil.example/jwks/',
            ]),
        ));

        $this->provision($this->provider->provisioningToken());

        $this->assertResponseCode(401);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
    }

    public function testRefusesMissingOrMalformedAuthorization(): void
    {
        $this->provision(null);
        $this->assertResponseCode(401);

        $this->configRequest(['headers' => ['Authorization' => 'Basic abc', 'Content-Type' => 'application/json']]);
        $this->post('/auth/provision', (string)json_encode($this->body()));
        $this->assertResponseCode(401);
    }

    public function testRefusesMalformedBody(): void
    {
        $this->configRequest(['headers' => [
            'Authorization' => 'Bearer ' . $this->provider->provisioningToken(),
            'Content-Type' => 'application/json',
        ]]);
        $this->post('/auth/provision', 'not json');
        $this->assertResponseCode(400);

        $this->provision($this->provider->provisioningToken(), ['client_secret' => '']);
        $this->assertResponseCode(400);

        $this->provision($this->provider->provisioningToken(), ['required_group' => ['a']]);
        $this->assertResponseCode(400);
    }

    public function testOnlyPost(): void
    {
        $this->get('/auth/provision');

        $this->assertResponseCode(405);
    }

    public function testSwitchedOff(): void
    {
        Configure::write('RockSoftwareUsers.provisioning', false);

        $this->provision($this->provider->provisioningToken());

        $this->assertResponseCode(404);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
    }

    public function testRateLimited(): void
    {
        Configure::write('RockSoftwareUsers.provisioning.rateLimit', 2);
        $this->provision('x.y.z');
        $this->provision('x.y.z');
        $this->assertResponseCode(401);

        $this->provision($this->provider->provisioningToken());

        $this->assertResponseCode(429);
        $this->assertNull($this->fetchTable('RockSoftware/Users.Settings')->current());
    }

    public function testSecretNeverReachesTheLog(): void
    {
        $this->provision($this->provider->provisioningToken());
        $this->provision($this->provider->provisioningToken(['aud' => 'other-client']));

        $log = implode("\n", Log::engine('provision_test')->read());
        $this->assertStringContainsString('provisioned', $log);
        $this->assertStringContainsString('refused', $log);
        $this->assertStringNotContainsString(self::SECRET, $log);
    }

    /**
     * @param string|null $token The bearer token, or none.
     * @param array<string, mixed> $override Body fields to change.
     * @return void
     */
    private function provision(?string $token, array $override = []): void
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $this->configRequest(['headers' => $headers]);
        $this->post('/auth/provision', (string)json_encode($override + $this->body()));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return [
            'issuer' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => self::SECRET,
            'required_group' => 'app-staff',
        ];
    }
}
