<?php
declare(strict_types=1);

namespace TestApp;

use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RockSoftware\Users\UsersPlugin;

/**
 * A consuming application with its own sign-in: its own
 * AuthenticationMiddleware with a session, a password form's session and a
 * bearer-token API beside the provider. The plugin must fit into it without
 * taking over: no second authentication, no sign-in requirement, and its
 * token-authenticated requests and password sessions left alone.
 */
class OwnAuthApplication extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /**
     * @return void
     */
    public function bootstrap(): void
    {
        $this->addPlugin(UsersPlugin::class);
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $csrf = new CsrfProtectionMiddleware();
        $csrf->skipCheckCallback(
            fn(ServerRequestInterface $request): bool => str_starts_with($request->getUri()->getPath(), '/api/'),
        );

        return $middlewareQueue
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add($csrf)
            ->add(new AuthenticationMiddleware($this));
    }

    /**
     * Session, and a bearer token that is the user's email address (a test
     * stand-in for an API token): its identity is not a session the plugin
     * established.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService(['unauthenticatedRedirect' => '/users/login']);
        $resolver = ['className' => 'Authentication.Orm', 'userModel' => 'Users'];
        $service->loadAuthenticator('Authentication.Session', [
            'identifier' => ['Authentication.Token' => ['tokenField' => 'id', 'resolver' => $resolver]],
        ]);
        $service->loadAuthenticator('Authentication.Token', [
            'header' => 'Authorization',
            'tokenPrefix' => 'Bearer',
            'identifier' => ['Authentication.Token' => ['tokenField' => 'email', 'resolver' => $resolver]],
        ]);

        return $service;
    }

    /**
     * @param \Cake\Routing\RouteBuilder $routes The builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->connect('/dashboard', ['controller' => 'Dashboard', 'action' => 'index']);
        $routes->connect('/api/ping', ['controller' => 'Dashboard', 'action' => 'ping']);
        parent::routes($routes);
    }
}
