<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Controller;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Hash;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Service\PasswordSignIn;
use RockSoftware\Users\Session\SignInSession;
use RockSoftware\Users\Test\TestCase\FakeProvider;

/**
 * The sign-in page's email and password form.
 */
class PasswordSignInTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.SessionEvents',
        'plugin.RockSoftware/Users.Users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        Configure::write('RockSoftwareUsers.passwordSignIn', true);
    }

    protected function tearDown(): void
    {
        Configure::write('RockSoftwareUsers.passwordSignIn', false);
        parent::tearDown();
    }

    public function testTheFormIsOfferedOnlyWhereTheApplicationDeclaresPasswords(): void
    {
        $this->get('/auth');
        $this->assertResponseContains('name="password"');

        Configure::write('RockSoftwareUsers.passwordSignIn', false);
        $this->get('/auth');
        $this->assertResponseNotContains('name="password"');
    }

    public function testTheFormIsGoneOncePasswordSignInIsTurnedOff(): void
    {
        $user = $this->createUser('person@example.test', 'correct horse battery');
        $this->connect();
        $this->fetchTable('RockSoftware/Users.Settings')->setPasswordSignIn(false, (int)$user->get('id'));

        $this->get('/auth');
        $this->assertResponseContains('Sign in with Rock Software ID');
        $this->assertResponseNotContains('name="password"');

        $this->post('/auth/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);
        $this->assertRedirectContains('/auth');
        $this->assertSessionNotHasKey('Auth');
        $this->assertSame([SessionEventType::PASSWORD_SIGN_IN_OFF], $this->history());
    }

    public function testACorrectPasswordSignsIn(): void
    {
        $user = $this->createUser('person@example.test', 'correct horse battery');

        $this->post('/auth/password', [
            'email' => 'Person@Example.test',
            'password' => 'correct horse battery',
            'redirect' => '/dashboard?tab=1',
        ]);

        $this->assertRedirect('/dashboard?tab=1');
        $this->assertSame($user->get('id'), Hash::get($_SESSION, 'Auth.id'));
        $this->assertSame(SignInSession::METHOD_PASSWORD, Hash::get($_SESSION, SignInSession::METHOD));
        $this->assertSame([SessionEventType::SIGNED_IN], $this->history());
    }

    public function testAWrongPasswordIsRefusedAndRecorded(): void
    {
        $user = $this->createUser('person@example.test', 'correct horse battery');

        $this->post('/auth/password', ['email' => 'person@example.test', 'password' => 'wrong']);

        $this->assertRedirectContains('/auth');
        $this->assertFlashMessage('The email address or password is wrong.');
        $this->assertSessionNotHasKey('Auth');
        $event = $this->fetchTable('RockSoftware/Users.SessionEvents')->find()->contain(['SessionEventTypes'])->firstOrFail();
        $this->assertSame(SessionEventType::WRONG_PASSWORD, $event->session_event_type->value);
        $this->assertSame($user->get('id'), $event->user_id);
        $this->assertSame('person@example.test', $_SESSION['RockSoftwareUsers']['passwordEmail'] ?? null, 'the address is kept for the form');
    }

    public function testAnUnknownAddressGetsTheSameAnswer(): void
    {
        $this->post('/auth/password', ['email' => 'nobody@example.test', 'password' => 'whatever']);

        $this->assertFlashMessage('The email address or password is wrong.');
        $this->assertSame([SessionEventType::UNKNOWN_EMAIL], $this->history());
    }

    public function testAProviderUsersUnusablePasswordNeverMatches(): void
    {
        $this->createUser('person@example.test', null);

        $this->post('/auth/password', ['email' => 'person@example.test', 'password' => '!rsu:x']);

        $this->assertSessionNotHasKey('Auth');
    }

    public function testADeactivatedUserIsRefused(): void
    {
        $this->createUser('person@example.test', 'correct horse battery', false);

        $this->post('/auth/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);

        $this->assertSessionNotHasKey('Auth');
        $this->assertFlashMessage('You have no access to this application.');
    }

    public function testAPasswordDoesNotOpenAccessThatHasEnded(): void
    {
        $user = $this->createUser('person@example.test', 'correct horse battery');
        $user->set('access_until', DateTime::now()->subDays(1));
        $this->fetchTable('Users')->saveOrFail($user, ['validate' => false]);

        $this->post('/auth/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);

        $this->assertSessionNotHasKey('Auth');
        $this->assertFlashMessage('Your access to this application has ended.');
    }

    public function testTooManyFailuresForOneAddressAreThrottled(): void
    {
        $this->createUser('person@example.test', 'correct horse battery');
        for ($i = 0; $i < PasswordSignIn::MAX_FAILURES_PER_EMAIL; $i++) {
            $this->post('/auth/password', ['email' => 'person@example.test', 'password' => 'wrong ' . $i]);
        }

        $this->post('/auth/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);

        $this->assertSessionNotHasKey('Auth');
        $this->assertFlashMessage(
            'Too many failed sign-ins. Try again in 15 minutes, or sign in with Rock Software ID.',
        );
        $this->assertSame(SessionEventType::THROTTLED, $this->history()[0]);
    }

    public function testAnOutdatedHashIsReplaced(): void
    {
        $user = $this->createUser('person@example.test', null);
        $users = $this->fetchTable('Users');
        $user->set('password', password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]));
        $users->saveOrFail($user, ['validate' => false]);

        $this->post('/auth/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);

        $this->assertSessionHasKey('Auth');
        $hash = (string)$users->get($user->get('id'))->get('password');
        $this->assertFalse((new DefaultPasswordHasher())->needsRehash($hash));
    }

    public function testGetGoesBackToTheSignInPage(): void
    {
        $this->get('/auth/password');

        $this->assertRedirect('/auth');
    }

    /**
     * The history's event types, newest first.
     *
     * @return array<string>
     */
    private function history(): array
    {
        return $this->fetchTable('RockSoftware/Users.SessionEvents')->find()
            ->contain(['SessionEventTypes'])
            ->orderBy(['SessionEvents.id' => 'DESC'])
            ->all()
            ->map(fn($event) => $event->session_event_type->value)
            ->toList();
    }

    private function connect(): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'is_enabled' => true,
        ]);
    }

    private function createUser(string $email, ?string $password, bool $active = true): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'name' => 'Person',
            'password' => $password === null ? '!rsu:x' : (new DefaultPasswordHasher())->hash($password),
            'is_active' => $active,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
    }
}
