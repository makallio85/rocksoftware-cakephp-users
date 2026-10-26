<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\Settings\Settings;
use RockSoftware\Settings\SettingsPlugin;
use RockSoftware\Ui\UiPlugin;
use RockSoftware\Users\Test\TestCase\FakeProvider;
use RockSoftware\Users\UsersPlugin;

/**
 * The sign-in pages' markup and the layout they are rendered in: the same
 * pages in every application, in the UI plugin's sign-in card when the
 * application loads it and in the plugin's own card when it does not.
 */
class SignInPagesTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.EmergencySignIns',
        'plugin.RockSoftware/Users.Users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetchTable('RockSoftware/Users.Settings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'is_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removePlugins(['RockSoftware/Ui']);
        Configure::delete('Ui');
        Settings::set(SettingsPlugin::APPLICATION_NAME, 'Test App');
        Settings::set(SettingsPlugin::APPLICATION_ORGANIZATION_NAME, '');
        Configure::write('RockSoftwareUsers.layout', null);
        Configure::write('RockSoftwareUsers.passwordSignIn', false);
        parent::tearDown();
    }

    public function testWithoutTheUiPluginThePagesUseThePluginsOwnCard(): void
    {
        $this->assertSame('RockSoftware/Users.sign_in', UsersPlugin::signInLayout());

        $this->get('/auth');

        $this->assertResponseOk();
        $this->assertResponseContains('<div class="auth-card">');
        $this->assertResponseContains('<h1 class="h5 mb-0">Test App</h1>');
        $this->assertResponseContains('<title>Sign in · Test App</title>');
        $this->assertResponseNotContains('text-body-secondary small', 'no line under the name while it is empty');
        $this->assertResponseContains('class="btn btn-primary btn-lg"');
        $this->assertResponseContains('<i class="bi bi-shield-lock me-1"></i> Sign in with Rock Software ID');
        $this->assertResponseNotContains('<script', 'the page runs no script, so no CSP needs to allow one');
    }

    public function testThePluginsOwnCardShowsTheLineUnderTheName(): void
    {
        Settings::set(SettingsPlugin::APPLICATION_ORGANIZATION_NAME, 'Example Organisation');

        $this->get('/auth');

        $this->assertResponseContains('<h1 class="h5 mb-0">Test App</h1>');
        $this->assertResponseContains('<p class="text-body-secondary small mb-0">Example Organisation</p>');
    }

    public function testWithTheUiPluginThePagesUseItsCardAndTheSettings(): void
    {
        $this->loadPlugins([UiPlugin::class]);
        Settings::set(SettingsPlugin::APPLICATION_NAME, 'Example Console');
        Settings::set(SettingsPlugin::APPLICATION_ORGANIZATION_NAME, 'Example Organisation');
        Configure::write('RockSoftwareUsers.appName', 'Old Name');

        $this->assertSame('RockSoftware/Ui.auth', UsersPlugin::signInLayout());
        $this->get('/auth');

        $this->assertResponseOk();
        $this->assertResponseContains('<body class="kk-auth">');
        $this->assertResponseContains('<h1 class="h5 mt-3 mb-1">Example Console</h1>');
        $this->assertResponseContains('<p class="text-body-secondary small mb-0">Example Organisation</p>');
        $this->assertResponseContains('· Example Console</title>');
        $this->assertResponseNotContains('Test App');
        $this->assertResponseNotContains('Old Name', 'RockSoftwareUsers.appName is no longer read');
        $this->assertResponseContains('Sign in with Rock Software ID');
        Configure::delete('RockSoftwareUsers.appName');
    }

    public function testALayoutTheApplicationNamesWins(): void
    {
        $this->loadPlugins([UiPlugin::class]);
        Configure::write('RockSoftwareUsers.layout', 'default');

        $this->assertSame('default', UsersPlugin::signInLayout());
    }

    public function testTheNotConfiguredNoticeIsAWarning(): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store(['is_enabled' => false]);

        $this->get('/auth');

        $this->assertResponseContains('<div class="alert alert-warning mb-0" role="alert">');
        $this->assertResponseContains('<i class="bi bi-exclamation-triangle me-1"></i>');
        $this->assertResponseContains('<code>bin/cake rock_software_users configure</code>');
    }

    public function testAnErrorIsShownAsAnAlertWithoutAnInlineHandler(): void
    {
        $this->session(['Flash' => ['flash' => [[
            'message' => 'Your account has no access to this application.',
            'key' => 'flash',
            'element' => 'flash/error',
            'params' => [],
        ]]]]);

        $this->get('/auth');

        $this->assertResponseContains('<div class="alert alert-danger" role="alert">');
        $this->assertResponseContains('Your account has no access to this application.');
        $this->assertResponseNotContains('onclick');
    }

    public function testThePasswordFormUsesTheSameMarkup(): void
    {
        Configure::write('RockSoftwareUsers.passwordSignIn', true);

        $this->get('/auth');

        $this->assertResponseContains('<label class="form-label" for="email">');
        $this->assertResponseContains('class="form-control"');
        $this->assertResponseContains('class="btn btn-outline-secondary"');
        $this->assertResponseContains('or with your email address and password');
    }

    public function testTheEmergencyPageIsTheSameCard(): void
    {
        $users = $this->fetchTable('Users');
        $user = $users->saveOrFail($users->newEntity([
            'email' => 'admin@example.test',
            'name' => 'Admin Example',
            'password' => '!rsu:x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
        $token = $this->fetchTable('RockSoftware/Users.EmergencySignIns')->issue((int)$user->get('id'), 15);

        $this->get('/auth/emergency/' . $token);

        $this->assertResponseOk();
        $this->assertResponseContains('<div class="auth-card">');
        $this->assertResponseContains('<h2 class="h6 mb-2">Emergency sign-in</h2>');
        $this->assertResponseContains('class="btn btn-primary btn-lg"');
        $this->assertResponseNotContains('<script');
    }
}
