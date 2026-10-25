<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Http\Client;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Session\SignInSession;
use RockSoftware\Users\UsersPlugin;
use TestApp\OwnAuthApplication;

/**
 * The plugin inside an application with its own sign-in: its own
 * authentication, a password form's sessions and a bearer-token API. Every
 * case here is one a real application hit after a release; this test app
 * exists so the next one is caught in the plugin's own CI.
 */
class OwnAuthApplicationTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.SettingChanges',
        'plugin.RockSoftware/Users.Provisionings',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.Users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        Client::clearMockResponses();
        $this->configApplication(OwnAuthApplication::class, [CONFIG]);
        Configure::write('RockSoftwareUsers.signInUrl', '/users/login');
    }

    protected function tearDown(): void
    {
        Configure::write('RockSoftwareUsers.signInUrl', '/auth');
        Configure::write('RockSoftwareUsers.passwordSignIn', false);
        Client::clearMockResponses();
        parent::tearDown();
    }

    public function testBearerTokenRequestsAreLeftAlone(): void
    {
        $this->createUser('api@example.test');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer api@example.test']]);

        $this->get('/api/ping');

        $this->assertResponseOk();
        $this->assertResponseContains('pong api@example.test');
    }

    public function testAPasswordSessionIsLeftAloneWhilePasswordSignInIsOn(): void
    {
        $user = $this->createUser('person@example.test');
        $this->connect();
        $this->session(['Auth' => $user]);

        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testTurningPasswordSignInOffEndsAPasswordSession(): void
    {
        Configure::write('RockSoftwareUsers.passwordSignIn', true);
        $user = $this->createUser('person@example.test');
        $this->connect();
        $this->fetchTable('RockSoftware/Users.Settings')->setPasswordSignIn(false, (int)$user->get('id'));
        $this->session(['Auth' => $user]);

        $this->get('/dashboard');

        $this->assertRedirectContains('/users/login');
    }

    public function testTheSettingsPageRendersForAProviderSession(): void
    {
        $user = $this->createUser('person@example.test');
        $this->session([
            'Auth' => $user,
            'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => time()],
        ]);

        $this->get('/auth/settings');

        $this->assertResponseOk();
        $this->assertResponseContains('Identity provider');
    }

    public function testClientMetadataNeedsNoSignInAndNamesTheApplicationsSignInPage(): void
    {
        $this->get(UsersPlugin::CLIENT_METADATA_PATH);

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $metadata = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('/auth/callback', $metadata['redirect_uri']);
        $this->assertSame(['/users/login', '/auth'], $metadata['post_logout_redirect_uris']);
        $this->assertSame('/auth/backchannel-logout', $metadata['backchannel_logout_uri']);
        $this->assertSame('/auth/provision', $metadata['provision_uri']);
    }

    public function testProvisionIsAnsweredBeforeTheApplicationsCsrfAndAuthentication(): void
    {
        $this->configRequest(['headers' => ['Authorization' => 'Bearer x.y.z', 'Content-Type' => 'application/json']]);

        $this->post(UsersPlugin::PROVISION_PATH, (string)json_encode([
            'issuer' => 'https://evil.example/application/o/app/',
            'client_id' => 'app',
            'client_secret' => 'secret',
        ]));

        $this->assertResponseCode(401);
    }

    /**
     * @return void
     */
    private function connect(): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'is_enabled' => true,
        ]);
    }

    /**
     * @param string $email The email address.
     * @return \Cake\Datasource\EntityInterface
     */
    private function createUser(string $email): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'name' => 'Person',
            'password' => 'x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
    }
}
