<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use RockSoftware\Settings\SettingsPlugin;
use RockSoftware\Users\Middleware\BearerTokenMiddleware;
use RockSoftware\Users\UsersPlugin;

/**
 * A consuming application with no sign-in wiring of its own: the queue an
 * application template ships, plus the plugin (and, for its bearer paths,
 * the CSRF exemption every application with them adds). Everything else is the
 * plugin's middleware hook at work.
 */
class Application extends BaseApplication
{
    /**
     * @return void
     */
    public function bootstrap(): void
    {
        $this->addPlugin(SettingsPlugin::class);
        $this->addPlugin(UsersPlugin::class);
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware())
            // The one line an application with bearer paths adds: those
            // requests carry no session, so no CSRF token either.
            ->add((new CsrfProtectionMiddleware())->skipCheckCallback(BearerTokenMiddleware::isBearerRequest(...)));
    }

    /**
     * @param \Cake\Routing\RouteBuilder $routes The builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->connect('/dashboard', ['controller' => 'Dashboard', 'action' => 'index']);
        $routes->connect('/mcp', ['controller' => 'Dashboard', 'action' => 'agent']);
        $routes->connect('/health', ['controller' => 'Dashboard', 'action' => 'health']);
        parent::routes($routes);
    }
}
