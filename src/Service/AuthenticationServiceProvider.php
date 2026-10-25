<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Cake\Core\Configure;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Session authentication for an application that has none of its own: the
 * session the plugin establishes after sign-in is the only authenticator, and
 * an anonymous request is sent to the sign-in page.
 */
class AuthenticationServiceProvider implements AuthenticationServiceProviderInterface
{
    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService([
            'unauthenticatedRedirect' => Configure::read('RockSoftwareUsers.signInUrl'),
            'queryParam' => 'redirect',
        ]);
        $service->loadAuthenticator('Authentication.Session');

        return $service;
    }
}
