<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\I18n\I18n;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Hash;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Session\SignInSession;
use RockSoftware\Users\Test\TestCase\FakeProvider;

class SignInControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.EmergencySignIns',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.SessionEvents',
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
        $this->fetchTable('RockSoftware/Users.Settings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'required_group' => 'app-staff',
            'is_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Client::clearMockResponses();
        EventManager::instance()->off('RockSoftwareUsers.afterSignIn');
        parent::tearDown();
    }

    public function testLoginRedirectsToProviderWithPkce(): void
    {
        $this->get('/auth/start?redirect=/messages');

        $this->assertResponseCode(302);
        $location = $this->_response->getHeaderLine('Location');
        $this->assertStringStartsWith('https://id.example.test/application/o/authorize/?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $flow = $this->sessionValue(SignInSession::FLOW);
        $this->assertSame($flow['state'], $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://app.example.test/auth/callback', $query['redirect_uri']);
        $this->assertSame('/messages', $flow['redirect']);
    }

    public function testLoginIgnoresOffSiteRedirect(): void
    {
        foreach (['https%3A%2F%2Fevil.example%2F', '%2F%2Fevil.example%2F', '%2F%5Cevil.example'] as $redirect) {
            $this->get('/auth/start?redirect=' . $redirect);

            $this->assertNull($this->sessionValue(SignInSession::FLOW)['redirect'], urldecode($redirect));
        }
    }

    public function testCallbackCreatesUserAndSignsIn(): void
    {
        $afterLogin = null;
        EventManager::instance()->on('RockSoftwareUsers.afterSignIn', function (EventInterface $event) use (&$afterLogin): void {
            $afterLogin = $event->getData('method');
        });

        $this->completeSignIn([]);

        $this->assertRedirect('/messages');
        $user = $this->userFor('subject-1');
        $this->assertSame('person@example.test', $user->email);
        $this->assertSame('Person One', $user->name);
        $this->assertStringStartsWith('!rsu:', $user->password, 'an unusable password fills the NOT NULL column');
        $this->assertSame($user->id, $this->sessionValue('Auth')->id);
        $this->assertTrue($this->sessionValue(SignInSession::AUTHENTICATED));
        $this->assertSame(SignInSession::METHOD_OIDC, $afterLogin);
    }

    public function testCallbackLinksExistingLocalUserByVerifiedEmail(): void
    {
        $local = $this->createLocalUser('person@example.test');

        $this->completeSignIn([]);

        $users = $this->fetchTable('Users');
        $this->assertSame(1, $users->find()->count());
        $this->assertSame($local->id, $this->userFor('subject-1')->id);
        $this->assertSame('hashed', $users->get($local->id)->password, 'the local password is kept');
    }

    public function testCallbackDoesNotLinkOnUnverifiedEmail(): void
    {
        $this->createLocalUser('person@example.test');

        $this->completeSignIn(['email_verified' => false]);

        // A second row with the same email breaks the unique rule: refused.
        $this->assertRedirect('/auth');
        $this->assertNull($this->fetchTable('RockSoftware/Users.Identities')->findByExternalId('subject-1'));
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesUserOutsideRequiredGroup(): void
    {
        $this->completeSignIn(['groups' => ['other-staff']]);

        $this->assertRedirect('/auth');
        $this->assertSame(0, $this->fetchTable('Users')->find()->count());
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesTokenSignedWithUnpublishedKey(): void
    {
        $this->completeSignIn([], FakeProvider::foreignKey());

        $this->assertRedirect('/auth');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongNonce(): void
    {
        $this->completeSignIn(['nonce' => 'not-the-nonce']);

        $this->assertRedirect('/auth');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongAudience(): void
    {
        $this->completeSignIn(['aud' => 'another-client']);

        $this->assertRedirect('/auth');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongState(): void
    {
        $this->get('/auth/start');
        $flow = $this->sessionValue(SignInSession::FLOW);
        $this->session([SignInSession::FLOW => $flow]);

        $this->get('/auth/callback?code=abc&state=forged');

        $this->assertRedirect('/auth');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testBackchannelLogoutEndsSessions(): void
    {
        $this->completeSignIn([]);
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $this->assertNull($identities->findByExternalId('subject-1')?->sessions_valid_from);

        // No CSRF token: the plugin answers before the application's CSRF check.
        $this->post('/auth/backchannel-logout', ['logout_token' => $this->provider->logoutToken()]);

        $this->assertResponseCode(200);
        $this->assertInstanceOf(DateTime::class, $identities->findByExternalId('subject-1')?->sessions_valid_from);
    }

    public function testBackchannelLogoutRejectsIdToken(): void
    {
        $this->post('/auth/backchannel-logout', ['logout_token' => $this->provider->idToken(['nonce' => 'x'])]);

        $this->assertResponseCode(400);
    }

    public function testEmergencyLinkSignsInOnceOnPost(): void
    {
        $local = $this->createLocalUser('admin@example.test');
        $token = $this->fetchTable('RockSoftware/Users.EmergencySignIns')->issue($local->id, 15);

        $this->get('/auth/emergency/' . $token);
        $this->assertResponseOk();
        $this->assertNull($this->sessionValue('Auth'));

        $this->enableCsrfToken();
        $this->post('/auth/emergency/' . $token);
        $this->assertRedirect('/dashboard');
        $this->assertSame(SignInSession::METHOD_EMERGENCY, $this->sessionValue(SignInSession::METHOD));

        $this->post('/auth/emergency/' . $token);
        $this->assertRedirect('/auth');
    }

    public function testSignInPageOffersRockSoftwareId(): void
    {
        $this->get('/auth?redirect=%2Fmessages');

        $this->assertResponseOk();
        $this->assertResponseContains('Sign in with Rock Software ID');
        $this->assertResponseContains('/auth/start?redirect=%2Fmessages');
    }

    public function testSignInPageExplainsMissingConfiguration(): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store(['is_enabled' => false]);

        $this->get('/auth');

        $this->assertResponseOk();
        $this->assertResponseContains('bin/cake rock_software_users configure');
        $this->assertResponseNotContains('Sign in with Rock Software ID');
    }

    public function testSignInPageIsTranslated(): void
    {
        I18n::setLocale('fi');
        try {
            $this->get('/auth');
        } finally {
            I18n::setLocale('en_US');
        }

        $this->assertResponseContains('Kirjaudu Rock Software ID:llä');
    }

    public function testTheTextsFollowAFixedLocale(): void
    {
        Configure::write('RockSoftwareUsers.locale', 'fi');
        try {
            $this->get('/auth');
        } finally {
            Configure::write('RockSoftwareUsers.locale', null);
            // The translator for this locale was built with the fixed language.
            I18n::clear();
        }

        $this->assertResponseContains('Kirjaudu Rock Software ID:llä');
    }

    public function testSignedInPersonIsSentOnFromSignInPage(): void
    {
        $this->completeSignIn([]);
        $this->session($_SESSION);

        $this->get('/auth');

        $this->assertRedirect('/dashboard');
    }

    public function testSignInStampsSessionAndLastLogin(): void
    {
        $this->completeSignIn([]);

        $this->assertIsInt($this->sessionValue(SignInSession::ESTABLISHED_AT));
        $identity = $this->fetchTable('RockSoftware/Users.Identities')->findByExternalId('subject-1');
        $this->assertInstanceOf(DateTime::class, $identity?->last_signed_in_at);
    }

    public function testDeletedUsersIdentityIsReplaced(): void
    {
        $this->completeSignIn([]);
        $this->fetchTable('Users')->deleteAll([]);
        // A fresh browser: the first token response and session are gone.
        Client::clearMockResponses();
        $this->provider->serveMetadata();
        $_SESSION = [];

        $this->completeSignIn([]);

        $this->assertRedirect('/messages');
        $this->assertSame(1, $this->fetchTable('RockSoftware/Users.Identities')->find()->count());
        $this->assertSame('person@example.test', $this->userFor('subject-1')->email);
    }

    public function testNobodySignsInBeforeTheirAccessStarts(): void
    {
        $users = $this->fetchTable('Users');
        $user = $this->createLocalUser('person@example.test');
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $user->id, 'external_id' => 'subject-1'],
            ['accessibleFields' => ['*' => true]],
        ));

        $user->set('access_from', DateTime::now()->addDays(2));
        $users->saveOrFail($user, ['validate' => false]);
        $this->completeSignIn([]);
        $this->assertRedirect('/auth');
        $this->assertFlashMessageContains('Your access to this application starts on');
        $this->assertSessionNotHasKey('Auth');
        $this->assertContains('access_not_started', $this->historyTypes());
    }

    public function testNobodySignsInAfterTheirAccessEnds(): void
    {
        $users = $this->fetchTable('Users');
        $user = $this->createLocalUser('person@example.test');
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $user->id, 'external_id' => 'subject-1'],
            ['accessibleFields' => ['*' => true]],
        ));
        $user->set('access_until', DateTime::now()->subMinutes(1));
        $users->saveOrFail($user, ['validate' => false]);

        $this->completeSignIn([]);

        $this->assertRedirect('/auth');
        $this->assertFlashMessage('Your access to this application has ended.');
        $this->assertContains('access_ended', $this->historyTypes());
    }

    /**
     * @return array<string>
     */
    private function historyTypes(): array
    {
        return $this->fetchTable('RockSoftware/Users.SessionEvents')->find()
            ->contain(['SessionEventTypes'])
            ->all()
            ->map(fn($event) => $event->session_event_type->value)
            ->toList();
    }

    public function testAUserDeactivatedInTheApplicationStaysOut(): void
    {
        $users = $this->fetchTable('Users');
        $user = $this->createLocalUser('person@example.test');
        $user->set('is_active', false);
        $users->saveOrFail($user, ['validate' => false]);
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $user->id, 'external_id' => 'subject-1'],
            ['accessibleFields' => ['*' => true]],
        ));

        $this->completeSignIn([]);

        $this->assertRedirect('/auth');
        $this->assertFlashMessage('You have no access to this application.');
        $this->assertFalse($users->get($user->id)->is_active, 'the provider does not reopen it');
        $types = $this->fetchTable('RockSoftware/Users.SessionEvents')->find()
            ->contain(['SessionEventTypes'])
            ->orderBy(['SessionEvents.id' => 'ASC'])
            ->all()
            ->map(fn($event) => $event->session_event_type->value)
            ->toList();
        $this->assertSame([SessionEventType::USER_INACTIVE], $types);
    }

    public function testTheAppsClaimIsKeptForTheMenu(): void
    {
        $this->completeSignIn(['apps' => [
            ['name' => 'Bookings', 'url' => 'https://bookings.example.test/'],
            ['name' => 'Not https', 'url' => 'http://plain.example.test/'],
            ['name' => 'Script', 'url' => 'javascript:alert(1)'],
            'garbage',
        ]]);

        $this->assertSame(
            [['name' => 'Bookings', 'url' => 'https://bookings.example.test/']],
            $this->sessionValue(SignInSession::APPS),
        );
    }

    public function testARefusalOutsideTheGroupIsRecorded(): void
    {
        $this->completeSignIn(['groups' => ['other']]);

        $event = $this->fetchTable('RockSoftware/Users.SessionEvents')->find()->contain(['SessionEventTypes'])->firstOrFail();
        $this->assertSame(SessionEventType::NOT_IN_GROUP, $event->session_event_type->value);
        $this->assertSame('person@example.test', $event->email);
    }

    public function testDisabledSettingRefusesLogin(): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store(['is_enabled' => false]);

        $this->get('/auth/start');

        $this->assertRedirect('/auth');
    }

    /**
     * A value from the session the last request ended with.
     *
     * @param string $key Session key.
     * @return mixed
     */
    private function sessionValue(string $key): mixed
    {
        // The integration session writes through to $_SESSION; the trait's
        // own snapshot is taken before the request runs.
        return Hash::get($_SESSION ?? [], $key);
    }

    /**
     * Runs login, then the callback with an ID token carrying these claims.
     *
     * @param array<string, mixed> $claims Claim overrides.
     * @param string|null $signingKey Sign with another key.
     * @return void
     */
    private function completeSignIn(array $claims, ?string $signingKey = null): void
    {
        $this->get('/auth/start?redirect=/messages');
        $flow = $this->sessionValue(SignInSession::FLOW);

        $this->provider->serveIdToken($this->provider->idToken($claims + ['nonce' => $flow['nonce']], $signingKey));
        $this->session([SignInSession::FLOW => $flow]);
        $this->get('/auth/callback?code=the-code&state=' . $flow['state']);
    }

    /**
     * @param string $email Email address.
     * @return \Cake\Datasource\EntityInterface
     */
    private function createLocalUser(string $email): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'password' => 'hashed',
            'name' => 'Local User',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true]]));
    }

    /**
     * @param string $subject The provider's subject identifier.
     * @return \Cake\Datasource\EntityInterface
     */
    private function userFor(string $subject): EntityInterface
    {
        $identity = $this->fetchTable('RockSoftware/Users.Identities')->findByExternalId($subject);
        $this->assertNotNull($identity, 'linked to ' . $subject);

        return $this->fetchTable('Users')->get($identity->user_id);
    }
}
