<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Middleware;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Model\Table\SettingsTable;
use RockSoftware\Users\Session\SignInSession;

/**
 * The plugin inserts SessionCheckMiddleware itself; /dashboard stands in for
 * any page behind sign-in.
 */
class SessionCheckMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.SessionEvents',
        'plugin.RockSoftware/Users.Users',
    ];

    public function testCurrentSessionPasses(): void
    {
        $this->signIn($this->createUser(), time());

        $this->get('/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('dashboard');
    }

    public function testDeactivatedUserIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->signIn($user, time());
        $user->set('is_active', false);
        $this->fetchTable('Users')->saveOrFail($user);

        $this->get('/dashboard');

        $this->assertRedirect('https://app.example.test/auth');
        $this->assertFlashMessage('Your session has ended. Please sign in again.');
    }

    public function testDeletedUserIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->signIn($user, time());
        $this->fetchTable('Users')->deleteOrFail($user);

        $this->get('/dashboard');

        $this->assertRedirect('https://app.example.test/auth');
    }

    public function testSessionThePluginDidNotEstablishIsLeftAlone(): void
    {
        // An application's own sign-in (a password form, a token) is its to
        // manage: no plugin keys in the session, an identity the plugin may not
        // even recognise as a user.
        $user = $this->createUser();
        $this->session(['Auth' => $user]);
        $this->fetchTable('Users')->deleteOrFail($user);

        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testPasswordSessionEndsOncePasswordSignInIsTurnedOff(): void
    {
        Configure::write('RockSoftwareUsers.passwordSignIn', true);
        try {
            $user = $this->createUser();
            $settings = $this->fetchTable('RockSoftware/Users.Settings');
            $settings->store([
                'issuer_url' => 'https://id.example.test/application/o/app/',
                'client_id' => 'app-client',
                'client_secret' => 'the-secret',
                'is_enabled' => true,
            ]);

            $this->session(['Auth' => $user]);
            $this->get('/dashboard');
            $this->assertResponseOk('a password session runs while password sign-in is on');

            $settings->setPasswordSignIn(false, (int)$user->get('id'));
            $this->session(['Auth' => $user]);
            $this->get('/dashboard');
            $this->assertRedirect('https://app.example.test/auth');
            $this->assertFlashMessage('Password sign-in has been turned off. Please sign in with Rock Software ID.');

            // A session signed in through the provider carries on.
            $this->signIn($user, time());
            $this->get('/dashboard');
            $this->assertResponseOk();
        } finally {
            Configure::write('RockSoftwareUsers.passwordSignIn', false);
        }
    }

    public function testPasswordSessionIsLeftAloneInAnApplicationWithoutAPasswordForm(): void
    {
        $user = $this->createUser();
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $settings->store([
            'issuer_url' => 'https://id.example.test/application/o/app/',
            'client_id' => 'app-client',
            'client_secret' => 'the-secret',
            'is_enabled' => true,
        ]);
        $settings->setPasswordSignIn(false, (int)$user->get('id'));

        $this->session(['Auth' => $user]);
        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testSessionEndedByBackchannelLogoutIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->signIn($user, time() - 60);
        $this->endSessions(DateTime::now());

        $this->get('/dashboard');

        $this->assertRedirect('https://app.example.test/auth');
    }

    public function testSessionStartedAfterBackchannelLogoutPasses(): void
    {
        $user = $this->createUser();
        $this->endSessions(DateTime::now()->subMinutes(5));
        $this->signIn($user, time());

        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testAnonymousRequestToSignInPagePasses(): void
    {
        $this->get('/auth');

        $this->assertResponseOk();
    }

    public function testTheLongestSessionIsEnforced(): void
    {
        $user = $this->createUser();
        $this->connect()->setSessionLimits(8, 0, 180);

        $this->signIn($user, time() - 7 * 3600);
        $this->get('/dashboard');
        $this->assertResponseOk();

        $this->signIn($user, time() - 9 * 3600);
        $this->get('/dashboard');
        $this->assertRedirect('https://app.example.test/auth');
        $this->assertFlashMessage('Your session has expired. Please sign in again.');
    }

    public function testAnIdleSessionIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->connect()->setSessionLimits(0, 30, 180);
        $session = ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => time() - 7200];

        $this->session(['Auth' => $user, 'RockSoftwareUsers' => $session + ['lastSeenAt' => time() - 600]]);
        $this->get('/dashboard');
        $this->assertResponseOk();

        $this->session(['Auth' => $user, 'RockSoftwareUsers' => $session + ['lastSeenAt' => time() - 3600]]);
        $this->get('/dashboard');
        $this->assertRedirect('https://app.example.test/auth');
    }

    public function testLimitsAreOffUntilSet(): void
    {
        $user = $this->createUser();
        $this->connect();
        $this->session(['Auth' => $user, 'RockSoftwareUsers' => [
            'authenticated' => true,
            'method' => SignInSession::METHOD_OIDC,
            'establishedAt' => time() - 30 * 86400,
            'lastSeenAt' => time() - 7 * 86400,
        ]]);

        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testThePluginsPasswordSessionEndsOncePasswordSignInIsTurnedOff(): void
    {
        Configure::write('RockSoftwareUsers.passwordSignIn', true);
        try {
            $user = $this->createUser();
            $session = ['authenticated' => true, 'method' => SignInSession::METHOD_PASSWORD, 'establishedAt' => time()];
            $this->session(['Auth' => $user, 'RockSoftwareUsers' => $session]);
            $this->get('/dashboard');
            $this->assertResponseOk();

            $this->connect()->setPasswordSignIn(false, (int)$user->get('id'));
            $this->session(['Auth' => $user, 'RockSoftwareUsers' => $session]);
            $this->get('/dashboard');
            $this->assertRedirect('https://app.example.test/auth');
        } finally {
            Configure::write('RockSoftwareUsers.passwordSignIn', false);
        }
    }

    /**
     * @return \RockSoftware\Users\Model\Table\SettingsTable
     */
    private function connect(): SettingsTable
    {
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $settings->store([
            'issuer_url' => 'https://id.example.test/application/o/app/',
            'client_id' => 'app-client',
            'client_secret' => 'the-secret',
            'is_enabled' => true,
        ]);

        return $settings;
    }

    /**
     * @return \Cake\Datasource\EntityInterface
     */
    private function createUser(): EntityInterface
    {
        $users = $this->fetchTable('Users');
        $user = $users->saveOrFail($users->newEntity([
            'email' => 'person@example.test',
            'password' => '!rsu:x',
            'name' => 'Person',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $user->id, 'external_id' => 'subject-9'],
            ['accessibleFields' => ['*' => true]],
        ));

        return $user;
    }

    /**
     * What a back-channel logout does.
     *
     * @param \Cake\I18n\DateTime $from Sessions started before this end.
     * @return void
     */
    private function endSessions(DateTime $from): void
    {
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identity = $identities->findByExternalId('subject-9');
        $this->assertNotNull($identity);
        $identity->set('sessions_valid_from', $from);
        $identities->saveOrFail($identity);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param int $establishedAt When the session started.
     * @return void
     */
    private function signIn(EntityInterface $user, int $establishedAt): void
    {
        // SignInSession's keys are dotted paths under `RockSoftwareUsers`.
        $this->session([
            'Auth' => $user,
            'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => $establishedAt],
        ]);
    }
}
