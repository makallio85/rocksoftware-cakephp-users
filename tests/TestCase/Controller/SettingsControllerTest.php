<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\Http\TestSuite\HttpClientTrait;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Controller\SettingsController;
use RockSoftware\Users\Session\SignInSession;
use RockSoftware\Users\UsersPlugin;

class SettingsControllerTest extends TestCase
{
    use HttpClientTrait;
    use IntegrationTestTrait;

    private const GOOD = 'https://id.example.test/application/o/app/';
    private const OTHER = 'https://id-other.example.test/application/o/app/';
    private const MISSING = 'https://id.example.test/application/o/apq/';

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.SettingChanges',
        'plugin.RockSoftware/Users.Provisionings',
        'plugin.RockSoftware/Users.Users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        Client::clearMockResponses();
        $this->enableCsrfToken();
        Configure::write(
            'RockSoftwareUsers.settingsAccess',
            fn($identity): bool => $identity->get('email') === 'admin@example.test',
        );

        foreach ([self::GOOD, self::OTHER] as $issuer) {
            $this->mockClientGet($issuer . '.well-known/openid-configuration', $this->newClientResponse(
                200,
                ['Content-Type: application/json'],
                (string)json_encode([
                    'issuer' => $issuer,
                    'authorization_endpoint' => $issuer . 'authorize/',
                    'token_endpoint' => $issuer . 'token/',
                    'jwks_uri' => $issuer . 'jwks/',
                ]),
            ));
        }
        $this->mockClientGet(self::MISSING . '.well-known/openid-configuration', $this->newClientResponse(404));
    }

    protected function tearDown(): void
    {
        Configure::write('RockSoftwareUsers.settingsAccess', null);
        Client::clearMockResponses();
        parent::tearDown();
    }

    public function testAnonymousRequestIsSentToSignIn(): void
    {
        $this->get('/auth/settings');

        $this->assertRedirectContains('/auth?redirect=%2Fauth%2Fsettings');
    }

    public function testAccessIsTheApplicationsDecision(): void
    {
        $this->signIn($this->createUser('someone@example.test'));
        $this->get('/auth/settings');
        $this->assertResponseCode(403);

        // No callable configured: anyone signed in, who is already in the
        // application's required group.
        Configure::write('RockSoftwareUsers.settingsAccess', null);
        $this->signIn($this->createUser('admin@example.test'));
        $this->get('/auth/settings');
        $this->assertResponseOk();
    }

    public function testNotConfiguredPageShowsTheForm(): void
    {
        $this->signIn($this->createUser('admin@example.test'));

        $this->get('/auth/settings');

        $this->assertResponseOk();
        $this->assertResponseContains('Not configured yet');
        $this->assertResponseContains('<input type="url" name="issuer_url"');
        $this->assertResponseContains('<input type="text" name="client_id"');
        $this->assertResponseContains('<input type="password" name="client_secret"');
        $this->assertResponseContains('https://app.example.test/auth/callback');
        $this->assertResponseNotContains('<select');
    }

    public function testSaveTestsTheServerThenStores(): void
    {
        $admin = $this->createUser('admin@example.test');
        $this->signIn($admin);

        $this->post('/auth/settings/update', [
            'issuer_url' => rtrim(self::GOOD, '/'),
            'client_id' => 'app',
            'client_secret' => 'the-secret',
            'required_group' => 'app-staff',
        ]);

        $this->assertRedirect('/auth/settings');
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $setting = $settings->current();
        $this->assertNotNull($setting);
        $this->assertSame(self::GOOD, $setting->issuer_url);
        $this->assertSame('app-staff', $setting->required_group);
        $this->assertTrue($setting->is_enabled);
        $this->assertNotSame('the-secret', $setting->client_secret, 'stored encrypted');
        $this->assertSame('the-secret', $settings->clientSecret($setting));
        /** @var \RockSoftware\Users\Model\Table\SettingChangesTable $changes */
        $changes = $this->fetchTable('RockSoftware/Users.SettingChanges');
        $this->assertSame($admin->id, $changes->latest()?->user_id);

        $this->get('/auth/settings');
        $this->assertResponseContains('Connected.');
        $this->assertResponseContains('Last changed by Admin');
        $this->assertResponseNotContains('the-secret');
    }

    public function testServerThatDoesNotAnswerIsNotSaved(): void
    {
        $this->signIn($this->createUser('admin@example.test'));
        $this->post('/auth/settings/update', [
            'issuer_url' => self::GOOD, 'client_id' => 'app', 'client_secret' => 's1', 'required_group' => '',
        ]);

        $this->post('/auth/settings/update', [
            'issuer_url' => self::MISSING, 'client_id' => 'app', 'client_secret' => '', 'required_group' => '',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('answered 404');
        $this->assertResponseContains('is-invalid');
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $this->assertSame(self::GOOD, $settings->current()?->issuer_url);
        $this->assertSame(1, $this->fetchTable('RockSoftware/Users.SettingChanges')->find()->count());
    }

    public function testBlankSecretKeepsTheStoredOne(): void
    {
        $this->signIn($this->createUser('admin@example.test'));
        $this->post('/auth/settings/update', [
            'issuer_url' => self::GOOD, 'client_id' => 'app', 'client_secret' => 'kept', 'required_group' => 'x',
        ]);
        $this->post('/auth/settings/update', [
            'issuer_url' => self::OTHER, 'client_id' => 'app', 'client_secret' => '', 'required_group' => '',
        ]);

        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $setting = $settings->current();
        $this->assertNotNull($setting);
        $this->assertSame(self::OTHER, $setting->issuer_url);
        $this->assertNull($setting->required_group);
        $this->assertSame('kept', $settings->clientSecret($setting));
    }

    public function testFirstSaveNeedsASecret(): void
    {
        $this->signIn($this->createUser('admin@example.test'));

        $this->post('/auth/settings/update', [
            'issuer_url' => self::GOOD, 'client_id' => 'app', 'client_secret' => '', 'required_group' => '',
        ]);

        $this->assertResponseContains('Enter the client secret');
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $this->assertNull($settings->current());
    }

    public function testTestConnectionSavesNothing(): void
    {
        $this->signIn($this->createUser('admin@example.test'));

        $this->post('/auth/settings/test', [
            'issuer_url' => self::GOOD, 'client_id' => 'app', 'client_secret' => 'x', 'required_group' => '',
        ]);

        $this->assertResponseContains('The identity server answered. Nothing was saved.');
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $this->assertNull($settings->current());
    }

    public function testGetOnFormActionsReturnsToTheForm(): void
    {
        $this->signIn($this->createUser('admin@example.test'));

        $this->get('/auth/settings/test');
        $this->assertRedirect('/auth/settings');

        $this->get('/auth/settings/update');
        $this->assertRedirect('/auth/settings');
    }

    public function testAllowProvisioningOpensAnHourAndIsRecorded(): void
    {
        $admin = $this->createUser('admin@example.test');
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $settings->store([
            'issuer_url' => self::GOOD,
            'client_id' => 'app-client',
            'client_secret' => 'the-secret',
            'is_enabled' => true,
        ]);
        $this->assertFalse($settings->provisioningOpen());

        $this->signIn($admin);
        $this->enableCsrfToken();
        $this->post('/auth/settings/allow-provisioning');

        $this->assertRedirect('/auth/settings');
        $this->assertTrue($settings->provisioningOpen());
        $until = $settings->current()->provisioning_open_until;
        $this->assertTrue($until->greaterThan(DateTime::now()->addMinutes(59)));
        $this->assertTrue($until->lessThanOrEquals(DateTime::now()->addMinutes(60)));
        $this->assertSame($admin->id, $this->fetchTable('RockSoftware/Users.SettingChanges')->latest()?->user_id);
    }

    public function testPageShowsProvisioningState(): void
    {
        $admin = $this->createUser('admin@example.test');
        $this->signIn($admin);
        $this->get('/auth/settings');
        $this->assertResponseContains('Waiting. Rock Software ID connects this application');

        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $settings->store([
            'issuer_url' => self::GOOD,
            'client_id' => 'app-client',
            'client_secret' => 'the-secret',
            'is_enabled' => true,
        ]);
        $this->fetchTable('RockSoftware/Users.Provisionings')->saveOrFail($this->fetchTable('RockSoftware/Users.Provisionings')->newEntity([
            'jti' => 'j1', 'issuer_url' => self::GOOD, 'client_id' => 'app-client',
        ]));
        $this->signIn($admin);
        $this->get('/auth/settings');
        $this->assertResponseContains('Closed. Rock Software ID cannot change this connection.');
        $this->assertResponseContains('Allow Rock Software ID to connect again');
        $this->assertResponseContains('Connected by Rock Software ID,');

        $settings->openProvisioning(60);
        $this->signIn($admin);
        $this->get('/auth/settings');
        $this->assertResponseContains('Open until');
        $this->assertResponseNotContains('Allow Rock Software ID to connect again');
    }

    public function testPageHidesProvisioningWhenSwitchedOff(): void
    {
        Configure::write('RockSoftwareUsers.provisioning', false);
        $this->signIn($this->createUser('admin@example.test'));
        $this->get('/auth/settings');
        Configure::write('RockSoftwareUsers.provisioning', UsersPlugin::DEFAULTS['provisioning']);

        $this->assertResponseOk();
        $this->assertResponseNotContains('Connection by Rock Software ID');
    }

    public function testPasswordSwitchOnlyWhereTheApplicationHasAPasswordForm(): void
    {
        $admin = $this->createUser('admin@example.test');
        $this->connect(self::GOOD);
        $this->signInThroughProvider($admin);
        $this->get('/auth/settings');
        $this->assertResponseContains('Not available. This application has no passwords of its own');
        $this->assertResponseNotContains('Turn password sign-in');

        Configure::write('RockSoftwareUsers.passwordSignIn', true);
        try {
            $this->signInThroughProvider($admin);
            $this->get('/auth/settings');
            $this->assertResponseContains('Turn password sign-in off');
            $this->assertResponseNotContains('Not available. This application has no passwords');
        } finally {
            Configure::write('RockSoftwareUsers.passwordSignIn', false);
        }
    }

    public function testPasswordSignInIsAllowedBeforeAnyConnection(): void
    {
        $this->assertTrue($this->fetchTable('RockSoftware/Users.Settings')->passwordSignInAllowed());
    }

    public function testTurningPasswordSignInOffAndOn(): void
    {
        Configure::write('RockSoftwareUsers.passwordSignIn', true);
        $events = [];
        EventManager::instance()->on(SettingsController::EVENT_PASSWORD_SIGN_IN_CHANGED, function ($event) use (&$events): void {
            $events[] = $event->getData('enabled');
        });
        try {
            $admin = $this->createUser('admin@example.test');
            $this->connect(self::GOOD);
            $settings = $this->fetchTable('RockSoftware/Users.Settings');

            $this->signInThroughProvider($admin);
            $this->post('/auth/settings/password-sign-in', ['enabled' => '0']);
            $this->assertRedirect('/auth/settings');
            $this->assertFalse($settings->passwordSignInAllowed());
            $this->assertSame($admin->id, $settings->current()->password_sign_in_changed_by);
            $this->assertSame($admin->id, $this->fetchTable('RockSoftware/Users.SettingChanges')->latest()?->user_id);

            $this->signInThroughProvider($admin);
            $this->get('/auth/settings');
            $this->assertResponseContains('Turn password sign-in on');
            $this->assertResponseContains('Password sign-in turned off by Admin');

            // Back on works from any session allowed on the page.
            $this->signIn($admin);
            $this->post('/auth/settings/password-sign-in', ['enabled' => '1']);
            $this->assertTrue($settings->passwordSignInAllowed());
            $this->assertSame([false, true], $events);
        } finally {
            Configure::write('RockSoftwareUsers.passwordSignIn', false);
            EventManager::instance()->off(SettingsController::EVENT_PASSWORD_SIGN_IN_CHANGED);
        }
    }

    public function testPasswordSignInCannotBeTurnedOffFromAPasswordOrEmergencySession(): void
    {
        Configure::write('RockSoftwareUsers.passwordSignIn', true);
        try {
            $admin = $this->createUser('admin@example.test');
            $this->connect(self::GOOD);
            $settings = $this->fetchTable('RockSoftware/Users.Settings');

            // A password session: no plugin keys at all.
            $this->session(['Auth' => $admin]);
            $this->get('/auth/settings');
            $this->assertResponseContains('sign in through Rock Software ID first');
            $this->session(['Auth' => $admin]);
            $this->post('/auth/settings/password-sign-in', ['enabled' => '0']);
            $this->assertTrue($settings->passwordSignInAllowed());

            // An emergency link.
            $this->session([
                'Auth' => $admin,
                'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_EMERGENCY, 'establishedAt' => time()],
            ]);
            $this->post('/auth/settings/password-sign-in', ['enabled' => '0']);
            $this->assertTrue($settings->passwordSignInAllowed());
        } finally {
            Configure::write('RockSoftwareUsers.passwordSignIn', false);
        }
    }

    public function testPasswordSignInStaysOnWhenTheServerDoesNotAnswer(): void
    {
        Configure::write('RockSoftwareUsers.passwordSignIn', true);
        try {
            $admin = $this->createUser('admin@example.test');
            $this->connect(self::MISSING);

            $this->signInThroughProvider($admin);
            $this->post('/auth/settings/password-sign-in', ['enabled' => '0']);

            $this->assertTrue($this->fetchTable('RockSoftware/Users.Settings')->passwordSignInAllowed());
            $this->assertFlashMessage(
                'Password sign-in stays on: the identity server does not answer. ' .
                'https://id.example.test/application/o/apq/.well-known/openid-configuration answered 404.',
            );
        } finally {
            Configure::write('RockSoftwareUsers.passwordSignIn', false);
        }
    }

    public function testSessionLimitsAreSavedAndShown(): void
    {
        $admin = $this->createUser('admin@example.test');
        $this->connect(self::GOOD);
        $this->signIn($admin);

        $this->post('/auth/settings/sessions', [
            'session_max_hours' => '12',
            'session_idle_minutes' => '60',
            'session_event_retention_days' => '90',
        ]);

        $this->assertRedirect('/auth/settings');
        $this->assertSame(
            ['max_hours' => 12, 'idle_minutes' => 60, 'retention_days' => 90],
            $this->fetchTable('RockSoftware/Users.Settings')->sessionLimits(),
        );
        $this->get('/auth/settings');
        $this->assertResponseContains('name="session_idle_minutes" min="0" value="60"');
    }

    public function testAllowProvisioningNeedsPostAndAccess(): void
    {
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $settings->store([
            'issuer_url' => self::GOOD,
            'client_id' => 'app-client',
            'client_secret' => 'the-secret',
            'is_enabled' => true,
        ]);
        $this->signIn($this->createUser('admin@example.test'));

        $this->get('/auth/settings/allow-provisioning');
        $this->assertRedirect('/auth/settings');
        $this->assertFalse($settings->provisioningOpen());

        Configure::write('RockSoftwareUsers.settingsAccess', fn($identity): bool => false);
        $this->enableCsrfToken();
        $this->post('/auth/settings/allow-provisioning');
        Configure::write('RockSoftwareUsers.settingsAccess', null);
        $this->assertResponseCode(403);
        $this->assertFalse($settings->provisioningOpen());
    }

    /**
     * @param string $email Email address.
     * @return \Cake\Datasource\EntityInterface
     */
    private function connect(string $issuer): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store([
            'issuer_url' => $issuer,
            'client_id' => 'app-client',
            'client_secret' => 'the-secret',
            'is_enabled' => true,
        ]);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return void
     */
    private function signInThroughProvider(EntityInterface $user): void
    {
        $this->session([
            'Auth' => $user,
            'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => time()],
        ]);
    }

    private function createUser(string $email): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'name' => $email === 'admin@example.test' ? 'Admin' : 'Someone',
            'password' => '!rsu:x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return void
     */
    private function signIn(EntityInterface $user): void
    {
        $this->session([
            'Auth' => $user,
            'RockSoftwareUsers' => ['authenticated' => true, 'method' => 'oidc', 'establishedAt' => time()],
        ]);
    }
}
