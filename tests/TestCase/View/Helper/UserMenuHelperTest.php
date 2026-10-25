<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\View\Helper;

use Authentication\Identity;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use RockSoftware\Users\Session\SignInSession;
use RockSoftware\Users\Test\TestCase\FakeProvider;
use RockSoftware\Users\View\Helper\UserMenuHelper;

class UserMenuHelperTest extends TestCase
{
    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadPlugins(['RockSoftware/Users' => ['path' => dirname(__DIR__, 4) . DS]]);
        Router::reload();
        Router::createRouteBuilder('/')->scope('/', function ($routes): void {
            $routes->connect('/auth/sign-out', ['plugin' => 'RockSoftware/Users', 'controller' => 'SignIn', 'action' => 'signOut']);
        });
    }

    public function testNobodySignedInGetsNoItems(): void
    {
        $view = new View(new ServerRequest(['session' => new Session()]));

        $this->assertSame([], (new UserMenuHelper($view))->items());
        $this->assertSame('', $view->element('RockSoftware/Users.user_menu'));
    }

    public function testTheItemsDescribeThePersonTheirAppsAndSignOut(): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'is_enabled' => true,
        ]);
        $view = new View($this->signedInRequest());

        $items = (new UserMenuHelper($view))->items();

        $this->assertSame(['header', 'app', 'app', 'link', 'sign_out'], array_column($items, 'type'));
        $this->assertSame('Anna Example', $items[0]['label']);
        $this->assertStringStartsWith('anna@example.test · Signed in with Rock Software ID at ', $items[0]['detail']);
        $this->assertTrue($items[1]['current'], 'the application the person is in');
        $this->assertFalse($items[2]['current']);
        $this->assertSame('https://id.example.test/if/user/', $items[3]['url']);
        $this->assertSame('post', $items[4]['method']);
    }

    public function testTheElementRendersTheSameItems(): void
    {
        $html = (new View($this->signedInRequest()))->element('RockSoftware/Users.user_menu');

        $this->assertStringContainsString('<details class="rsu-user-menu">', $html);
        $this->assertStringContainsString('>AE<', $html);
        $this->assertStringContainsString('href="https://bookings.example.test/"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('action="/auth/sign-out"', $html, 'sign-out is a POST');
        $this->assertStringNotContainsString('/if/user/', $html, 'no account link without a connection');
    }

    private function signedInRequest(): ServerRequest
    {
        $users = $this->fetchTable('Users');
        $user = $users->saveOrFail($users->newEntity([
            'email' => 'anna@example.test',
            'name' => 'Anna Example',
            'password' => '!rsu:x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
        $session = new Session();
        $session->write([
            SignInSession::AUTHENTICATED => true,
            SignInSession::METHOD => SignInSession::METHOD_OIDC,
            SignInSession::ESTABLISHED_AT => time(),
            SignInSession::APPS => [
                ['name' => 'This App', 'url' => 'https://app.example.test/'],
                ['name' => 'Bookings', 'url' => 'https://bookings.example.test/'],
            ],
        ]);
        $request = new ServerRequest([
            'session' => $session,
            'environment' => ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on'],
        ]);

        return $request->withAttribute('identity', new Identity($user));
    }
}
