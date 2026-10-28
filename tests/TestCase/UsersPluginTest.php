<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase;

use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Middleware\BackchannelLogoutMiddleware;
use RockSoftware\Users\Middleware\BearerTokenMiddleware;
use RockSoftware\Users\Middleware\ClientMetadataMiddleware;
use RockSoftware\Users\Middleware\ProvisionMiddleware;
use RockSoftware\Users\Middleware\RequireSignInMiddleware;
use RockSoftware\Users\Middleware\ScimMiddleware;
use RockSoftware\Users\Middleware\SessionCheckMiddleware;
use RockSoftware\Users\Service\AuthenticationServiceProvider;
use RockSoftware\Users\UsersPlugin;
use TestApp\Application;

class UsersPluginTest extends TestCase
{
    protected function tearDown(): void
    {
        UsersPlugin::applyDefaults();
        Configure::write('RockSoftwareUsers.authentication', 'auto');
        Configure::write('RockSoftwareUsers.requireSignIn', 'auto');
        parent::tearDown();
    }

    public function testMigratingClearsTheSchemaCache(): void
    {
        (new UsersPlugin())->bootstrap(new Application(CONFIG));
        Cache::setConfig('rsu_test_model', ['className' => 'Array']);
        ConnectionManager::setConfig('rsu_cached', [
            'url' => 'sqlite:///:memory:',
            'cacheMetadata' => 'rsu_test_model',
        ]);
        Cache::write('rsu_cached_users', 'the table before the migration', 'rsu_test_model');

        try {
            EventManager::instance()->dispatch(new Event('Migration.afterMigrate'));

            $this->assertNull(Cache::read('rsu_cached_users', 'rsu_test_model'));
        } finally {
            ConnectionManager::drop('rsu_cached');
            Cache::drop('rsu_test_model');
        }
    }

    public function testWiresAnApplicationWithoutAuthentication(): void
    {
        $queue = (new UsersPlugin())->middleware($this->applicationQueue());

        $this->assertSame([
            RoutingMiddleware::class,
            BodyParserMiddleware::class,
            BackchannelLogoutMiddleware::class,
            ProvisionMiddleware::class,
            ScimMiddleware::class,
            ClientMetadataMiddleware::class,
            CsrfProtectionMiddleware::class,
            AuthenticationMiddleware::class,
            BearerTokenMiddleware::class,
            SessionCheckMiddleware::class,
            RequireSignInMiddleware::class,
        ], $this->classes($queue));
    }

    public function testKeepsTheApplicationsOwnAuthentication(): void
    {
        $own = new AuthenticationMiddleware(new AuthenticationServiceProvider());
        $queue = (new UsersPlugin())->middleware($this->applicationQueue()->add($own));

        $this->assertSame([
            RoutingMiddleware::class,
            BodyParserMiddleware::class,
            BackchannelLogoutMiddleware::class,
            ProvisionMiddleware::class,
            ScimMiddleware::class,
            ClientMetadataMiddleware::class,
            CsrfProtectionMiddleware::class,
            AuthenticationMiddleware::class,
            BearerTokenMiddleware::class,
            SessionCheckMiddleware::class,
        ], $this->classes($queue), 'no second authentication, and the application decides what is public');
    }

    public function testDoesNotAddASecondSessionCheck(): void
    {
        $queue = $this->applicationQueue()
            ->add(new AuthenticationMiddleware(new AuthenticationServiceProvider()))
            ->add(new SessionCheckMiddleware());

        $classes = $this->classes((new UsersPlugin())->middleware($queue));

        $this->assertSame(1, array_count_values($classes)[SessionCheckMiddleware::class]);
    }

    public function testRequireSignInCanBeSwitchedOff(): void
    {
        Configure::write('RockSoftwareUsers.requireSignIn', false);

        $classes = $this->classes((new UsersPlugin())->middleware($this->applicationQueue()));

        $this->assertNotContains(RequireSignInMiddleware::class, $classes);
        $this->assertContains(AuthenticationMiddleware::class, $classes);
    }

    public function testDefaultsFillWhatTheApplicationLeftOut(): void
    {
        Configure::write('RockSoftwareUsers', ['signInRedirect' => '/home']);

        UsersPlugin::applyDefaults();

        $this->assertSame('/home', Configure::read('RockSoftwareUsers.signInRedirect'));
        $this->assertSame('/auth', Configure::read('RockSoftwareUsers.signInUrl'));
        $this->assertSame([], Configure::read('RockSoftwareUsers.systemUsers'));
        $this->assertNull(Configure::read('RockSoftwareUsers.timezone'), 'follows the application');
        $this->assertNull(Configure::read('RockSoftwareUsers.layout'), 'picked by signInLayout()');
    }

    /**
     * @return \Cake\Http\MiddlewareQueue
     */
    private function applicationQueue(): MiddlewareQueue
    {
        return (new MiddlewareQueue())
            ->add(new RoutingMiddleware(new Application(CONFIG)))
            ->add(new BodyParserMiddleware())
            ->add(new CsrfProtectionMiddleware());
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $queue The queue.
     * @return list<string>
     */
    public function testTheDisplayTimezoneFollowsTheApplication(): void
    {
        try {
            Configure::delete('Ui');
            Configure::delete('App.displayTimezone');
            $this->assertSame('Europe/Helsinki', UsersPlugin::displayTimezone());

            Configure::write('App.displayTimezone', 'America/New_York');
            $this->assertSame('America/New_York', UsersPlugin::displayTimezone());

            Configure::write('Ui.displayTimezone', 'Asia/Tokyo');
            $this->assertSame('Asia/Tokyo', UsersPlugin::displayTimezone());

            Configure::write('RockSoftwareUsers.timezone', 'UTC');
            $this->assertSame('UTC', UsersPlugin::displayTimezone(), 'the plugin\'s own setting, when set, wins');

            Configure::write('RockSoftwareUsers.timezone', 'Not/AZone');
            $this->assertSame('Asia/Tokyo', UsersPlugin::displayTimezone(), 'a zone PHP does not know is skipped');
        } finally {
            Configure::delete('Ui');
            Configure::delete('App.displayTimezone');
            Configure::write('RockSoftwareUsers.timezone', null);
        }
    }

    private function classes(MiddlewareQueue $queue): array
    {
        $classes = [];
        foreach ($queue as $middleware) {
            $classes[] = $middleware::class;
        }

        return $classes;
    }
}
